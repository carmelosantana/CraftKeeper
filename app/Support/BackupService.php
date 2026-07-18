<?php

namespace App\Support;

use App\Models\CatalogCacheEntry;
use App\Models\CatalogSourceState;
use App\Models\Setting;
use App\Operations\InputRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Creates and restores CraftKeeper's own application-state backups, under
 * {data_root}/backups. Task 19's ambiguity resolution #2 in full:
 *
 * - The database copy uses SQLite's real ONLINE backup mechanism
 *   (`VACUUM INTO`, SQLite >= 3.27), not a raw `copy()` of the live
 *   database file — a raw copy of a file SQLite may be mid-write to can
 *   capture a torn, inconsistent snapshot; `VACUUM INTO` produces a
 *   complete, compacted, internally consistent copy in one atomic
 *   operation, safe to take while the application keeps serving requests.
 * - `settings.json`/`catalog-cache.json`/`config.json` are ADDITIONAL,
 *   human-readable exports for inspection without needing a SQLite
 *   client — they are NOT the restore mechanism (restore only ever reads
 *   `database.sqlite`) — so they are held to a stricter bar: genuinely
 *   secret-free content, not merely "whatever the database itself
 *   contains." `settings.json` is the ENTIRE `settings` table (which is
 *   non-secret by construction — see App\Models\Setting's own docblock;
 *   real secrets live in App\Models\Secret, deliberately never exported
 *   to any of these three files), defensively passed through
 *   App\Operations\InputRedactor::redact() in case a future setting is
 *   ever added under a sensitive-looking key name.
 * - `database.sqlite` DOES include the `secrets` table (restore fidelity:
 *   an operator restoring onto a fresh install expects RCON/AI credentials
 *   to come back too, not to have to re-enter them) — accepted per the
 *   task's own ambiguity resolution, because App\Models\Secret's `value`
 *   column is `encrypted` at the Eloquent-attribute level (AES-256-GCM,
 *   Laravel's APP_KEY), so the file on disk never holds plaintext. This
 *   is safe ONLY as long as the same APP_KEY is used to restore, which is
 *   documented here rather than silently assumed.
 * - Minecraft worlds are never included: this class never reads
 *   config('craftkeeper.minecraft_root') or anything under it at all.
 */
final class BackupService
{
    private const DATABASE_FILE = 'database.sqlite';

    private const SETTINGS_FILE = 'settings.json';

    private const CATALOG_CACHE_FILE = 'catalog-cache.json';

    private const CONFIG_FILE = 'config.json';

    private const MANIFEST_FILE = 'manifest.json';

    /**
     * Builds a backup archive and returns its absolute path. The caller
     * owns the returned file — this class does not prune old backups
     * (retention/scheduling, if any, is a future task's concern).
     */
    public function create(): string
    {
        $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
        $backupsDir = $dataRoot.'/backups';
        File::ensureDirectoryExists($backupsDir, 0755);

        $workDir = $backupsDir.'/.tmp-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workDir, 0755);

        try {
            $dbPath = $workDir.'/'.self::DATABASE_FILE;

            // SQLite's real online-backup statement: a single atomic
            // operation that produces a complete, internally consistent,
            // VACUUMed copy of the current database — never a partial or
            // torn snapshot, even while other connections are reading or
            // writing concurrently.
            DB::statement('VACUUM INTO ?', [$dbPath]);

            if (! File::exists($dbPath)) {
                throw new RuntimeException('VACUUM INTO did not produce a database copy.');
            }

            File::put($workDir.'/'.self::SETTINGS_FILE, $this->encode($this->nonSecretSettings()));
            File::put($workDir.'/'.self::CATALOG_CACHE_FILE, $this->encode($this->catalogCacheMetadata()));
            File::put($workDir.'/'.self::CONFIG_FILE, $this->encode($this->craftKeeperConfig()));

            $checksums = [
                self::DATABASE_FILE => hash_file('sha256', $dbPath),
                self::SETTINGS_FILE => hash_file('sha256', $workDir.'/'.self::SETTINGS_FILE),
                self::CATALOG_CACHE_FILE => hash_file('sha256', $workDir.'/'.self::CATALOG_CACHE_FILE),
                self::CONFIG_FILE => hash_file('sha256', $workDir.'/'.self::CONFIG_FILE),
            ];

            File::put($workDir.'/'.self::MANIFEST_FILE, $this->encode([
                'generated_at' => now()->toIso8601String(),
                'app_name' => config('app.name'),
                'checksums' => $checksums,
            ]));

            $zipPath = $backupsDir.'/backup-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Unable to create backup archive at {$zipPath}.");
            }

            foreach ([self::DATABASE_FILE, self::SETTINGS_FILE, self::CATALOG_CACHE_FILE, self::CONFIG_FILE, self::MANIFEST_FILE] as $name) {
                $zip->addFile($workDir.'/'.$name, $name);
            }

            $zip->close();

            return $zipPath;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Restores `database.sqlite` from a backup archive into
     * $targetDataRoot, which MUST be fresh (no existing database.sqlite)
     * — this deliberately refuses to silently overwrite a populated /data,
     * matching the task brief's "must restore into a FRESH /data"
     * requirement. Checksum-verifies the archived database against its
     * own manifest before writing anything, so a corrupted or tampered
     * archive is refused rather than silently restored.
     */
    public function restore(string $zipPath, string $targetDataRoot): void
    {
        File::ensureDirectoryExists($targetDataRoot, 0755);

        if (File::exists($targetDataRoot.'/'.self::DATABASE_FILE)) {
            throw new RuntimeException("Refusing to restore into {$targetDataRoot}: a database already exists there. Restore only into a fresh data directory.");
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open backup archive at {$zipPath}.");
        }

        $manifestRaw = $zip->getFromName(self::MANIFEST_FILE);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;

        if (! is_array($manifest) || ! isset($manifest['checksums'][self::DATABASE_FILE])) {
            $zip->close();

            throw new RuntimeException('Backup archive is missing a valid manifest.json with a database checksum.');
        }

        $dbContent = $zip->getFromName(self::DATABASE_FILE);

        if (! is_string($dbContent)) {
            $zip->close();

            throw new RuntimeException('Backup archive is missing database.sqlite.');
        }

        $actualChecksum = hash('sha256', $dbContent);
        $expectedChecksum = $manifest['checksums'][self::DATABASE_FILE];

        if (! hash_equals((string) $expectedChecksum, $actualChecksum)) {
            $zip->close();

            throw new RuntimeException('Backup archive is corrupted: database.sqlite does not match the checksum recorded in manifest.json.');
        }

        File::put($targetDataRoot.'/'.self::DATABASE_FILE, $dbContent);

        // The remaining files are reference-only exports (see class
        // docblock) — restored alongside the database for convenience,
        // but their absence/corruption is not fatal to a restore.
        foreach ([self::SETTINGS_FILE, self::CATALOG_CACHE_FILE, self::CONFIG_FILE, self::MANIFEST_FILE] as $name) {
            $content = $zip->getFromName($name);

            if (is_string($content)) {
                File::put($targetDataRoot.'/'.$name, $content);
            }
        }

        $zip->close();
    }

    /**
     * @return array<string, string|null>
     */
    private function nonSecretSettings(): array
    {
        /** @var array<string, string|null> $settings */
        $settings = Setting::query()->pluck('value', 'key')->all();

        return InputRedactor::redact($settings);
    }

    /**
     * Bookkeeping fields only — deliberately excludes each entry's
     * `payload` (the normalized catalog page/document itself). That data
     * is entirely re-fetchable from the live source on demand; keeping it
     * out of the backup keeps the archive small and avoids restoring
     * possibly-stale marketplace listings as if they were current.
     *
     * @return array{cache_entries: list<array<string, mixed>>, source_states: list<array<string, mixed>>}
     */
    private function catalogCacheMetadata(): array
    {
        $cacheMeta = array_values(CatalogCacheEntry::query()
            ->get(['cache_key', 'source', 'kind', 'etag', 'last_modified', 'fresh_until', 'expires_at'])
            ->map(fn (CatalogCacheEntry $entry) => [
                'cache_key' => $entry->cache_key,
                'source' => $entry->source,
                'kind' => $entry->kind,
                'etag' => $entry->etag,
                'last_modified' => $entry->last_modified,
                'fresh_until' => $entry->fresh_until->toIso8601String(),
                'expires_at' => $entry->expires_at->toIso8601String(),
            ])
            ->all());

        $sourceStates = array_values(CatalogSourceState::query()
            ->get()
            ->map(fn (CatalogSourceState $state) => [
                'source' => $state->source,
                'status' => $state->status,
                'consecutive_failures' => $state->consecutive_failures,
                'last_success_at' => $state->last_success_at?->toIso8601String(),
                'last_attempt_at' => $state->last_attempt_at?->toIso8601String(),
            ])
            ->all());

        return ['cache_entries' => $cacheMeta, 'source_states' => $sourceStates];
    }

    /**
     * config('craftkeeper') in full — data_root/minecraft_root paths,
     * plugin/AI timeout bounds, and the e2e-testing flag. None of these
     * are credentials (see config/craftkeeper.php in full); they exist
     * here purely so a restored environment's configuration can be
     * compared against what produced the backup.
     *
     * @return array<string, mixed>
     */
    private function craftKeeperConfig(): array
    {
        return (array) config('craftkeeper');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
