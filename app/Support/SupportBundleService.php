<?php

namespace App\Support;

use App\Ai\SecretRedactor;
use App\Http\Controllers\Concerns\PresentsOperations;
use App\Models\ConsoleEntry;
use App\Models\Operation;
use App\Models\Setting;
use App\Operations\InputRedactor;
use App\Operations\OperationStatus;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Builds a single, exportable, ZIP diagnostics archive an operator can
 * safely hand to a third party (or attach to a bug report) without ever
 * disclosing a secret. This is the security crux of Task 19 — see the
 * task brief's own framing ("a leaked secret here is a real exfil") — so
 * the EXCLUSION list below is the load-bearing part of this class, not
 * the inclusion list.
 *
 * Excluded, structurally (by never being queried/read at all — not by a
 * best-effort redaction pass applied afterward):
 * - App\Models\Secret (every RCON/AI credential).
 * - App\Models\AiConversation / App\Models\AiMessage (full chat content —
 *   `content` is a free-typed field an operator could have pasted
 *   anything, including a secret, into; excluded wholesale rather than
 *   attempting to redact it here).
 * - App\Models\ConfigChangePayload (the one genuinely raw, encrypted-at-
 *   rest secret value in the config pipeline — Task 8's own docblock
 *   calls this out explicitly).
 * - The `token`/hashed-token column of Laravel\Sanctum\PersonalAccessToken
 *   and anything from the `oauth_*` tables — only an aggregate COUNT is
 *   read (App\Support\IntegrationHealthChecker::api()/mcp()), never a row.
 * - Any file under {data_root}/quarantine or {data_root}/plugin-rollbacks
 *   (uploaded/downloaded plugin JARs) — this class never reads that
 *   directory at all.
 *
 * On top of that structural exclusion, EVERY text file this class writes
 * into the bundle is passed through App\Ai\SecretRedactor::
 * configuredAndSchemaSecretValues() (Task 19 fix pass) before being
 * written — a second, independent line of defense so that even a
 * currently-configured Secret value, or a schema `secret: true` config
 * field value (e.g. rcon.password, proxies.velocity.secret) that got
 * echoed somewhere it shouldn't have been (a log line, an operation's
 * `outcome` text), is still scrubbed before it reaches disk. Computed
 * ONCE per bundle (not once per file) and applied uniformly to every
 * file, including ones that should never contain a secret in the first
 * place, so a future bug that adds a new field can't silently bypass it.
 *
 * Earlier versions of this class called the single-config-path
 * redactKnownSecrets() instead, which only ever redacted the Secret
 * store half — there is no single "the config file" for a bundle that
 * spans the whole application, so the schema-discovered half (every
 * config file across the mounted Minecraft root, not just one) was
 * silently never applied here. That gap is what let a schema-flagged
 * secret value echoed into the Minecraft console (persisted verbatim by
 * App\Server\LogTailService into App\Models\ConsoleEntry, which does not
 * itself redact) reach `logs/console-recent.log` unmasked.
 */
final class SupportBundleService
{
    use PresentsOperations;

    private const RECENT_CONSOLE_ENTRIES = 200;

    private const RECENT_OPERATION_FAILURES = 20;

    private const APPLICATION_LOG_TAIL_BYTES = 65_536; // 64 KiB

    public function __construct(
        private readonly IntegrationHealthChecker $health,
        private readonly SecretRedactor $secretRedactor,
    ) {}

    /**
     * Assembles the bundle and returns the absolute path to the generated
     * zip file under {data_root}/support-bundles. The caller owns the
     * returned file (deleting it after it has been downloaded/sent is the
     * caller's responsibility — this class does not self-clean, mirroring
     * App\Support\BackupService's own contract).
     */
    public function create(): string
    {
        $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
        $bundleDir = $dataRoot.'/support-bundles';
        File::ensureDirectoryExists($bundleDir, 0755);

        $timestamp = now()->format('Ymd-His');
        $zipPath = $bundleDir.'/support-bundle-'.$timestamp.'-'.bin2hex(random_bytes(4)).'.zip';

        $files = [
            'versions.json' => $this->encode($this->versions()),
            'health.json' => $this->encode($this->healthSnapshot()),
            'permissions.json' => $this->encode($this->permissions($dataRoot)),
            'settings.redacted.json' => $this->encode($this->redactedSettings()),
            'operations/recent-failures.json' => $this->encode($this->recentOperationFailures()),
            'logs/console-recent.log' => $this->recentConsoleLog(),
            'logs/application-recent.log' => $this->recentApplicationLog(),
        ];

        // Redact every text file against every currently configured
        // Secret value AND every schema `secret: true` config field
        // value discoverable across the mounted Minecraft root (Task 19
        // fix pass) — the belt-and-suspenders pass described in this
        // class's docblock. The value set is gathered ONCE here, then
        // applied uniformly to every file, including ones that should
        // never contain a secret in the first place, so a future bug
        // that adds a new field can't silently bypass it.
        $secretValues = $this->secretRedactor->configuredAndSchemaSecretValues();

        $redactedFiles = [];
        foreach ($files as $name => $content) {
            $redactedFiles[$name] = $this->secretRedactor->redact($content, $secretValues)->text;
        }

        $checksums = [];
        foreach ($redactedFiles as $name => $content) {
            $checksums[$name] = hash('sha256', $content);
        }

        $manifest = $this->encode([
            'generated_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'checksums' => $checksums,
        ]);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create support bundle archive at {$zipPath}.");
        }

        foreach ($redactedFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->addFromString('manifest.json', $manifest);
        $zip->close();

        return $zipPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function versions(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'os' => PHP_OS_FAMILY,
            'sqlite_driver' => config('database.default'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthSnapshot(): array
    {
        return [
            'integrations' => array_map(
                fn (IntegrationStatus $status) => $status->toArray(),
                $this->health->snapshot(),
            ),
        ];
    }

    /**
     * Real, freshly-evaluated filesystem permission checks — never a
     * static "ok", mirroring App\Http\Controllers\HealthController's own
     * "every check reflects a real, freshly-evaluated condition" rule.
     *
     * @return array<string, mixed>
     */
    private function permissions(string $dataRoot): array
    {
        $minecraftRoot = (string) config('craftkeeper.minecraft_root');

        return [
            'data_root' => $this->pathPermissions($dataRoot),
            'minecraft_root' => $this->pathPermissions($minecraftRoot),
            'backups_dir' => $this->pathPermissions($dataRoot.'/backups'),
        ];
    }

    /**
     * @return array{path: string, exists: bool, readable: bool, writable: bool}
     */
    private function pathPermissions(string $path): array
    {
        return [
            'path' => $path,
            'exists' => $path !== '' && File::exists($path),
            'readable' => $path !== '' && File::exists($path) && is_readable($path),
            'writable' => $path !== '' && File::exists($path) && is_writable($path),
        ];
    }

    /**
     * The ENTIRE `settings` table — every key App\Models\Setting stores is
     * already non-secret by construction (sensitive values live in
     * App\Models\Secret instead, which this class never queries at all —
     * see the class docblock). App\Operations\InputRedactor::redact() is
     * still applied defensively, by KEY NAME, in case a future setting is
     * ever added under an obviously sensitive key name.
     *
     * @return array<string, mixed>
     */
    private function redactedSettings(): array
    {
        $settings = Setting::query()->pluck('value', 'key')->all();

        return InputRedactor::redact($settings);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOperationFailures(): array
    {
        $failures = Operation::query()
            ->where('status', OperationStatus::Failed)
            ->latest('finished_at')
            ->limit(self::RECENT_OPERATION_FAILURES)
            ->get();

        return array_values($failures->map(fn (Operation $operation) => $this->presentOperationSummary($operation))->all());
    }

    /**
     * The most recent bounded console lines already sanitized/size-capped
     * by App\Server\LogTailService before ever reaching
     * App\Models\ConsoleEntry (see that model's own docblock) — this only
     * adds the SupportBundleService-wide SecretRedactor pass on top.
     */
    private function recentConsoleLog(): string
    {
        $lines = ConsoleEntry::query()
            ->latest('occurred_at')
            ->limit(self::RECENT_CONSOLE_ENTRIES)
            ->get()
            ->reverse()
            ->map(fn (ConsoleEntry $entry) => sprintf('[%s] %s', $entry->occurred_at->toIso8601String(), $entry->line))
            ->implode("\n");

        return $lines === '' ? "No recent console output recorded.\n" : $lines."\n";
    }

    /**
     * A PHP stack-trace frame line, e.g.
     * `#0 /app/Foo.php(42): App\Models\Secret::put('rcon.password', 'super-secret-va...')`
     * or the terminal `#3 {main}`. PHP's own Exception::getTraceAsString()
     * (which Monolog's LineFormatter embeds for every logged Throwable)
     * truncates each string ARGUMENT to 15 characters — so a frame that
     * happens to pass a secret as an argument leaks exactly enough of it
     * to defeat exact-value redaction (redact() can only ever match a
     * COMPLETE known value, never a truncated prefix of one). Stripped
     * wholesale by recentApplicationLog() rather than attempting to mask
     * only the argument list, since a frame's argument list is free-form
     * and not reliably parseable — see that method's docblock.
     */
    private const STACK_TRACE_FRAME_PATTERN = '/^\s*#\d+\s+(\S.*\(\d+\):|\{main\})/';

    /**
     * The tail of this application's own log file (never the Minecraft
     * server's raw log — that never leaves the mounted volume through
     * this class). Bounded to the last 64 KiB so a huge log file can
     * never make bundle generation slow or unbounded.
     *
     * Task 19 fix pass: a raw tail can include Monolog's rendering of a
     * logged exception's stack trace, and every frame in it is a
     * truncated-argument leak risk (see self::STACK_TRACE_FRAME_PATTERN's
     * own docblock) that App\Ai\SecretRedactor's exact-value matching can
     * never catch. Every stack-trace frame line is dropped from the tail
     * before it is returned; the surrounding message/context lines (the
     * actually useful diagnostic content) are kept untouched.
     */
    private function recentApplicationLog(): string
    {
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path) || ! is_readable($path)) {
            return "No application log file was found.\n";
        }

        $size = @filesize($path);

        if ($size === false) {
            return "The application log file could not be inspected.\n";
        }

        $offset = max(0, $size - self::APPLICATION_LOG_TAIL_BYTES);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return "The application log file could not be opened.\n";
        }

        fseek($handle, $offset);
        $tail = stream_get_contents($handle);
        fclose($handle);

        if ($tail === false || $tail === '') {
            return "The application log is currently empty.\n";
        }

        return $this->stripStackTraceFrames($tail);
    }

    /**
     * @see self::STACK_TRACE_FRAME_PATTERN
     */
    private function stripStackTraceFrames(string $tail): string
    {
        $lines = preg_split('/\R/', $tail) ?: [$tail];

        $kept = array_filter(
            $lines,
            fn (string $line): bool => preg_match(self::STACK_TRACE_FRAME_PATTERN, $line) !== 1,
        );

        return implode("\n", $kept);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
