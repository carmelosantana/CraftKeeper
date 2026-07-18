# Backups, restore, and support bundles

CraftKeeper distinguishes two different exports, for two different
purposes — do not confuse one for the other:

| | Backup | Support bundle |
|---|---|---|
| Purpose | Disaster recovery of CraftKeeper's own state | Troubleshooting / sharing diagnostics |
| Where | Settings > Backups | Settings > Advanced |
| Contains secrets? | Yes — encrypted, tied to `APP_KEY` | No — structurally excluded, plus redacted |
| Contains Minecraft world data? | No — never | No — never |
| Restorable? | Yes (manual procedure below) | No — read-only diagnostics, not a restore source |

Neither export ever touches `/minecraft` or anything under it —
`App\Support\BackupService` and `App\Support\SupportBundleService` never
read `config('craftkeeper.minecraft_root')` at all. Your Minecraft
world, plugins, and configuration files are not part of either archive
and are backed up (if at all) by whatever mechanism already manages
your Minecraft server's own volume.

## Backups

**Create one:** Settings > Backups > "Create backup". This runs SQLite's
own online-backup statement (`VACUUM INTO`) against the live database —
a single atomic operation that produces a complete, internally
consistent copy even while CraftKeeper keeps serving requests (never a
raw file copy of a database that might be mid-write).

**Contents of the downloaded `.zip`:**

- `database.sqlite` — the full application database: users, operations,
  config revisions, audit events, plugin state, API tokens (hashed, not
  plaintext), MCP grants, and the `secrets` table (RCON password, any
  configured AI API key) **encrypted** under your application's
  `APP_KEY`. Restoring this file restores your RCON/AI credentials
  automatically — you do not need to re-enter them — but **only as long
  as you restore into an environment using the same `APP_KEY`** the
  backup was taken with. Losing `APP_KEY` means these specific fields
  cannot be decrypted even if the rest of the restore succeeds.
- `settings.json`, `catalog-cache.json`, `config.json` — human-readable,
  secret-free exports of the same non-secret settings, catalog
  bookkeeping, and `config('craftkeeper')` values, for inspection without
  a SQLite client. These are **not** what restore reads — they exist
  purely for convenience.
- `manifest.json` — a generation timestamp and a SHA-256 checksum for
  every file in the archive.

### Restoring a backup

There is intentionally **no self-service "Restore" button** in V1:
restoring replaces the running application's own database file, which
would mean interrupting the very request that triggered it. Restore is a
manual operational step:

1. **Stop** the CraftKeeper container.
2. Make sure the target `/data` is **fresh** — either a brand-new
   `craftkeeper_data` volume, or (if reusing the same volume
   deliberately) remove the existing `database.sqlite` yourself first.
   This is a deliberate safety property of the underlying restore logic
   (`App\Support\BackupService::restore()` refuses outright to write over
   an existing `database.sqlite`) — do not skip verifying `/data` is
   actually empty of a prior database.
3. Unzip the backup archive and verify its checksum before trusting it:
   ```bash
   unzip -p backup-20260717-120000-abcd1234.zip manifest.json
   sha256sum <(unzip -p backup-20260717-120000-abcd1234.zip database.sqlite)
   # compare against manifest.json's "database.sqlite" checksum
   ```
4. Place the archive's `database.sqlite` at `{DATA_ROOT}/database.sqlite`
   (i.e. inside the fresh volume/mount from step 2).
5. **Start** the CraftKeeper container again. `docker/entrypoint.sh` runs
   `migrate --force` on boot, which is a no-op against an already-current
   schema — your restored data comes up as-is.

This reproduces exactly what `BackupService::restore()` does
programmatically (checksum verification, refusing a non-empty target)
— it just is not yet wired to a CLI command or a web action an operator
can click. If you need this scripted (e.g. as part of an automated
disaster-recovery pipeline), `App\Support\BackupService::restore(string
$zipPath, string $targetDataRoot): void` is the tested, reusable
primitive to call from a one-off `artisan tinker` invocation or a small
custom command.

### Retention

CraftKeeper does not currently prune old backups automatically — each
"Create backup" click adds a new file under `{DATA_ROOT}/backups/`.
Delete old ones from Settings > Backups (or directly from the volume)
according to whatever retention policy fits your storage budget.

## Support bundles

**Download one:** Settings > Advanced > "Download support bundle".
This is a **read-only diagnostic export** — it has no restore
counterpart and is not a substitute for a backup.

**What it's safe to share:** the bundle is built to be safe to attach to
a GitHub issue or hand to someone helping you troubleshoot, without a
secret leaking:

- It structurally never queries the `secrets` table, AI conversation
  content, or raw configuration-change payloads — those tables are never
  read by `App\Support\SupportBundleService` at all, so there is no
  redaction step that could fail to catch something it was never given.
- Every text file it **does** write is additionally passed through the
  same secret-redaction routine used for hosted-AI requests
  (`App\Ai\SecretRedactor::redactKnownSecrets()`), scrubbing any
  currently-configured secret value that might otherwise have leaked
  into a log line or an operation's recorded outcome text.
- It contains: application/health diagnostics (the same computation the
  Integrations page shows — RCON, AI, and catalog-source status), recent
  audit events, and version/environment metadata — enough to diagnose a
  problem, not enough to reproduce your credentials.

Even so, review a support bundle yourself before sharing it publicly —
it can still contain operationally identifying information (server
paths, plugin names, player names, timestamps) that isn't a *secret* by
CraftKeeper's own definition but that you may not want in a public issue
tracker regardless.

**Known, disclosed limitation:** secret redaction here (and everywhere
else in CraftKeeper) is **value-based** — it scrubs values CraftKeeper
already knows are secrets (stored `Secret` rows, schema-flagged config
fields). A credential CraftKeeper has never been told about (e.g. one
that only ever existed in a plugin's own config file under a field name
CraftKeeper's schemas don't recognize as secret) cannot be redacted,
because it isn't recognized as one. Review any bundle for anything
that looks like a credential before sharing it externally regardless.
