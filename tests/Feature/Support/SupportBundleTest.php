<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ApiIdempotencyKey;
use App\Models\ConfigChangePayload;
use App\Models\ConsoleEntry;
use App\Models\Operation;
use App\Models\Secret;
use App\Models\Setting;
use App\Models\User;
use App\Support\SupportBundleService;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

/**
 * Recursively walks every entry of a zip archive and returns one giant
 * haystack string of every member's contents concatenated together —
 * deliberately crude (this is a security test, not a parser) so a canary
 * hiding ANYWHERE in ANY file of the bundle, at any nesting depth, is
 * still caught.
 */
function bundleHaystack(string $zipPath): string
{
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBe(true);

    $haystack = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $haystack .= $name."\n";
        $contents = $zip->getFromIndex($i);

        if (is_string($contents)) {
            $haystack .= $contents."\n";
        }
    }

    $zip->close();

    return $haystack;
}

/*
|--------------------------------------------------------------------------
| The security crux: every secret canary, seeded anywhere the brief
| names, must be byte-for-byte absent from the generated bundle.
|--------------------------------------------------------------------------
*/
it('excludes every seeded secret canary from the generated support bundle', function () {
    // Canary 1: a Secret value (e.g. the RCON password).
    $secretCanary = 'CANARY-SECRET-RCON-PASSWORD-9f8e7d6c5b4a';
    Secret::put('rcon.password', $secretCanary);
    Secret::put('ai.api_key', 'CANARY-SECRET-AI-KEY-1a2b3c4d5e6f');

    // Canary 2: an AI chat message (App\Models\AiMessage::content).
    $chatCanary = 'CANARY-CHAT-CONTENT-a1b2c3d4e5f6-please-do-not-export-me';
    $conversation = AiConversation::query()->create(['title' => 'canary conversation']);
    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $chatCanary,
    ]);

    // Canary 3: a config-change secret value (App\Models\ConfigChangePayload,
    // the one genuinely raw, encrypted-at-rest secret value in the config
    // pipeline — Task 8).
    $configSecretCanary = 'CANARY-CONFIG-SECRET-VALUE-plugins-foo-token';
    $operation = Operation::factory()->create();
    ConfigChangePayload::query()->create([
        'operation_id' => $operation->id,
        'changes' => [
            ['kind' => 'set', 'path' => 'rcon.password', 'value' => $configSecretCanary],
        ],
    ]);

    // Canary 4: a live API token — both its one-time plaintext AND its
    // persisted hash must never appear.
    $token = $this->admin->createToken('canary token', ['server:read']);
    $tokenPlainText = $token->plainTextToken;
    $tokenHash = $token->accessToken->getAttributes()['token'];

    // Canary 5: an idempotency key's request hash (internal bookkeeping,
    // never meant to be surfaced — see App\Models\ApiIdempotencyKey).
    ApiIdempotencyKey::query()->create([
        'personal_access_token_id' => $token->accessToken->id,
        'endpoint' => 'POST /api/v1/config/canary',
        'idempotency_key' => 'CANARY-IDEMPOTENCY-KEY-value',
        'request_hash' => str_pad('CANARY-REQUEST-HASH-value', 64, '0'),
        'operation_id' => $operation->id,
    ]);

    $zipPath = app(SupportBundleService::class)->create();

    expect($zipPath)->toBeFile();

    $haystack = bundleHaystack($zipPath);

    expect($haystack)->not->toContain($secretCanary)
        ->and($haystack)->not->toContain('CANARY-SECRET-AI-KEY-1a2b3c4d5e6f')
        ->and($haystack)->not->toContain($chatCanary)
        ->and($haystack)->not->toContain($configSecretCanary)
        ->and($haystack)->not->toContain($tokenPlainText)
        ->and($haystack)->not->toContain($tokenHash)
        ->and($haystack)->not->toContain('CANARY-IDEMPOTENCY-KEY-value')
        ->and($haystack)->not->toContain('CANARY-REQUEST-HASH-value');

    @unlink($zipPath);
});

/*
|--------------------------------------------------------------------------
| Task 19 fix pass: a schema `secret: true` config field VALUE (not just
| a App\Models\Secret store row) must also be redacted from every file in
| the bundle, including logs/console-recent.log — App\Server\
| LogTailService persists console output verbatim (it strips ANSI and
| truncates, but never redacts), so a secret echoed to the Minecraft
| console lands unmasked in App\Models\ConsoleEntry unless this class's
| own redaction pass catches it.
|--------------------------------------------------------------------------
*/
it('redacts a schema-flagged config secret value from the support bundle, including a console log line that echoes it', function () {
    $minecraftRoot = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $minecraftRoot]);

    // The schema-secret value lives ONLY in the live config file — never
    // in the App\Models\Secret store — so this specifically exercises
    // App\Ai\SecretRedactor::discoverSchemaSecretLabels()'s half, not the
    // configuredSecretLabels() half already covered above.
    $schemaSecretCanary = 'canary-schema-secret-9d8c7b6a';
    file_put_contents(
        $minecraftRoot.'/server.properties',
        "motd=hello world\nrcon.password={$schemaSecretCanary}\n",
    );

    // The exact leak path the brief describes: the value gets echoed to
    // the Minecraft console and LogTailService persists it verbatim.
    ConsoleEntry::query()->create([
        'line' => "[RCON] Set rcon.password to {$schemaSecretCanary}",
        'occurred_at' => now(),
    ]);

    $zipPath = app(SupportBundleService::class)->create();

    expect($zipPath)->toBeFile();

    $haystack = bundleHaystack($zipPath);

    expect($haystack)->not->toContain($schemaSecretCanary);

    @unlink($zipPath);
    TempMinecraftRoot::destroy($minecraftRoot);
});

/*
|--------------------------------------------------------------------------
| Task 19 fix pass: the application log tail must never leak a truncated
| secret prefix via a PHP stack-trace frame — PHP's own
| Exception::getTraceAsString() truncates a string ARGUMENT to 15 chars,
| which exact-value redaction can never match (it is not the full known
| secret value, just a prefix of one).
|--------------------------------------------------------------------------
*/
it('drops a stack-trace frame carrying a truncated-secret argument from the application log tail', function () {
    $logPath = storage_path('logs/laravel.log');
    $original = File::exists($logPath) ? File::get($logPath) : null;

    // The truncated prefix PHP itself would produce for a 30+ character
    // secret argument (getTraceAsString() cuts a string arg to 15 chars,
    // then appends "...").
    $truncatedSecretPrefix = 'super-secret-va';
    $frameLine = "#0 /app/Models/Secret.php(42): App\\Models\\Secret::put('rcon.password', '{$truncatedSecretPrefix}...')";

    File::put($logPath, implode("\n", [
        '[2026-07-18 00:00:00] local.ERROR: Something failed {"exception":"[object] (RuntimeException(code: 0): boom at /app/Foo.php:10)',
        '[stacktrace]',
        $frameLine,
        '#1 {main}',
        '"}',
        '',
    ]));

    try {
        $zipPath = app(SupportBundleService::class)->create();

        expect($zipPath)->toBeFile();

        $haystack = bundleHaystack($zipPath);

        expect($haystack)->not->toContain($frameLine)
            ->and($haystack)->not->toContain($truncatedSecretPrefix);

        @unlink($zipPath);
    } finally {
        if ($original === null) {
            File::delete($logPath);
        } else {
            File::put($logPath, $original);
        }
    }
});

it('excludes full uploaded JAR bytes from the support bundle', function () {
    // A canary-shaped "JAR" dropped under the data root's quarantine
    // directory, exactly where a real uploaded artifact would briefly
    // live (App\Plugins\Concerns\QuarantinesArtifacts) — the bundle must
    // never recurse into it.
    $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
    $quarantineDir = $dataRoot.'/quarantine/canary-token';
    @mkdir($quarantineDir, 0755, true);
    file_put_contents($quarantineDir.'/artifact.jar', 'CANARY-JAR-BYTES-should-never-be-exported');

    $zipPath = app(SupportBundleService::class)->create();
    $haystack = bundleHaystack($zipPath);

    expect($haystack)->not->toContain('CANARY-JAR-BYTES-should-never-be-exported');

    $zip = new ZipArchive;
    $zip->open($zipPath);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        expect($zip->getNameIndex($i))->not->toEndWith('.jar');
    }
    $zip->close();

    @unlink($zipPath);
    @unlink($quarantineDir.'/artifact.jar');
    @rmdir($quarantineDir);
    @rmdir($dataRoot.'/quarantine');
});

/*
|--------------------------------------------------------------------------
| The bundle must still be genuinely useful: real health/versions/
| permissions/redacted-settings/checksum data, honestly reported.
|--------------------------------------------------------------------------
*/
it('includes honest, non-secret diagnostic content', function () {
    Setting::put('minecraft.server_path', '/minecraft');

    $zipPath = app(SupportBundleService::class)->create();
    $haystack = bundleHaystack($zipPath);

    expect($haystack)->toContain('manifest.json')
        ->and($haystack)->toContain('versions.json')
        ->and($haystack)->toContain('health.json')
        ->and($haystack)->toContain('/minecraft');

    @unlink($zipPath);
});

it('records a sha256 checksum for every file included in the bundle', function () {
    $zipPath = app(SupportBundleService::class)->create();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();

    expect($manifest)->toHaveKey('checksums');
    expect($manifest['checksums'])->not->toBeEmpty();

    foreach ($manifest['checksums'] as $file => $sha256) {
        expect($sha256)->toMatch('/^[0-9a-f]{64}$/');
    }

    @unlink($zipPath);
});
