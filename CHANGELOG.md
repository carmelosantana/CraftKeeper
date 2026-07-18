# Changelog

All notable changes to CraftKeeper are documented in this file. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versioning follows [Semantic Versioning](https://semver.org/).
`.github/workflows/release.yml` extracts each release's GitHub Release
notes directly from the matching `## [X.Y.Z]` section below, so keep the
heading format exact.

## [Unreleased]

Nothing yet.

## [1.0.1] - 2026-07-18

Repairs to the container image and the release pipeline, both of which ran
end-to-end against a real tag for the first time in 1.0.0. No application
behaviour changes.

The 1.0.0 image is functional, but 1.0.0 published no moving tags: its
release pipeline's smoke test, vulnerability scan, and signing job all
failed, and `publish-moving-tags` is deliberately gated behind them, so
`:latest`, `:v1`, and `:v1.0` were never created. This release restores them.

### Fixed

- **The application could not boot without a `.env` file.**
  `config/broadcasting.php` fell back to the `reverb` driver while
  `routes/channels.php` calls `Broadcast::channel()` during boot, so with no
  `REVERB_*` credentials the driver constructed Pusher with a null auth key
  and threw before the framework finished starting. This was not a loss of
  streaming — the application failed to start at all, and it took down
  `composer install` with it (its post-autoload-dump hook runs
  `artisan package:discover`). The fallback is now `log`; realtime streaming
  is opt-in via `BROADCAST_CONNECTION=reverb` plus credentials.

- **The container returned 503 on every request unless `DATA_ROOT` or
  `DB_DATABASE` was set.** `docker/entrypoint.sh` assigned both without
  `export`, so PHP never saw them: the entrypoint prepared and migrated
  `/data/database.sqlite` while the application read
  `storage/craftkeeper/database.sqlite`. Both are now exported, making the
  entrypoint's defaults authoritative.

- **SBOM generation failed the signing job.** `anchore/sbom-action` attempted
  to attach SBOMs to the GitHub Release, which requires `contents: write` —
  a permission the signing job intentionally does not hold. SBOMs are still
  published as workflow artifacts and attested to the image with `cosign`.

- **The vulnerability gate could never pass.** The scan blocked on 209
  CRITICAL/HIGH findings, 128 of them from `linux-libc-dev` — Linux kernel
  CVEs attributed to a headers package, for a kernel no container runs. It
  now reports only vulnerabilities with an available fix, which is an
  actionable signal; the few remaining kernel-header CVEs are listed with
  expiry dates in `.trivyignore.yaml`. The gate remains blocking, and full
  results at every severity are still uploaded to the Security tab.

- CI's Frontend job typechecked against Wayfinder-generated modules it never
  generated, and `image.yml` interpolated a build output directly into a shell
  command (flagged by zizmor).

### Changed

- `compose.example.yml` and `compose.legendary.yml` pin an explicit image tag
  instead of `:latest`, so a documented quickstart cannot break when moving
  tags are withheld by a release gate.

### Added

- Documentation and a compose file for running CraftKeeper alongside
  [Legendary Java Minecraft (Geyser + Floodgate)][legendary], the primary
  supported deployment. Two mismatches between the images are documented with
  verified fixes: a UID difference that makes every write fail while reads
  succeed, and a `755` volume root that breaks atomic writes to
  `server.properties` specifically.

- CI now boots the built image twice — once with only the environment
  `compose.example.yml` documents, and once with nothing but `APP_KEY` — and
  requires `/up` to return 200. Both bugs above were invisible to the existing
  suites because every test harness supplied configuration that real
  invocations do not.

[legendary]: https://github.com/TheRemote/Legendary-Java-Minecraft-Geyser-Floodgate

## [1.0.0] - 2026-07-18

The first stable release of CraftKeeper: an AGPL-3.0-or-later,
Docker-native control plane for a single Minecraft server, built as one
Laravel 13 + Inertia 3 / React 19 application with SQLite persistence.

### Added

- **Deployment.** A single multi-stage Docker image (PHP 8.4-FPM, Nginx,
  Supervisor, a non-root `craftkeeper` user) exposing HTTP on `:8080`,
  designed for Docker Compose behind Dokploy HTTPS. Never requires Docker
  socket access. `GET /up` reports application and database readiness.
- **Onboarding and authentication.** First-run setup creates exactly one
  administrator (registration disables itself afterward), with optional
  TOTP two-factor and recovery codes. Local username/password only — no
  external identity provider in V1.
- **Contained Minecraft filesystem.** Every read/write is canonically
  resolved beneath the mounted `/minecraft` directory, rejects path
  traversal, NUL bytes, and symlinks that escape the root, and only
  operates on regular files. Config discovery recognizes Server, Paper,
  Geyser/Floodgate, and plugin configuration across `.properties`,
  YAML, JSON, and TOML.
- **Guided, structured, and source config editing** that converge on one
  reviewable diff: validated, snapshotted, atomically written, and
  audited. Concurrent external edits produce a conflict for resolution,
  never a silent overwrite. Every edit is restorable from its revision
  history.
- **RCON control and server operations.** A bounded, timeout-protected
  Minecraft Source RCON client; a command policy separating safe
  predefined actions from elevated commands requiring fresh approval;
  graceful stop (`save-all flush` then `stop`); live console, player
  activity, and log tailing with realtime updates over Reverb.
- **Plugin lifecycle.** Discovery from the CraftKeeper Catalog, Hangar,
  and Modrinth, plus manual JAR upload — each verified by hash, parsed
  for metadata, staged in quarantine, and installed/updated/disabled/
  removed/rolled back with restart-required state tracked explicitly.
  The three catalog sources degrade independently of each other and of
  core operation.
- **Optional AI assistant.** Ollama and OpenAI-compatible providers,
  entirely optional and degrading cleanly when unavailable. Hosted AI
  requests have every known secret redacted before transmission, with
  the redaction disclosed to the operator. The AI may only propose
  configuration changes and safe RCON commands (plugin operations are an
  MCP-only proposal type, see below) — a human approves every mutation.
- **Versioned REST API (`/api/v1`).** Scoped personal-access tokens,
  idempotency-key support, cursor pagination, and a published
  `openapi.yaml` contract that is tested against the live route table.
- **MCP server.** OAuth 2.1 (authorization code + mandatory PKCE),
  per-grant scope ceilings independent of the live token's own claims,
  and a closed, three-tool surface (propose config change, propose
  plugin operation, run a safe RCON command) — no MCP tool can approve
  its own or any other proposal.
- **Settings, integrations, backups, and diagnostics.** A consolidated
  Integrations overview and Settings area; on-demand application-state
  backups (SQLite `VACUUM INTO`, checksummed, restorable only into a
  fresh `/data`); a secret-free support bundle for troubleshooting;
  optional Umami analytics, disabled by default and incapable of
  blocking any request path.
- **Design system and accessibility.** A shared `AppShell`, responsive
  down to 390px (tables become cards, split panes become bottom sheets
  below 768px), six themes (dark default; light, terracotta, emerald,
  slate, bronze), WCAG 2.2 AA contrast verified by computed-style
  end-to-end tests, keyboard traversal, and reduced-motion support.
- **CI/CD.** Separate PHP/frontend/browser/integration/container CI
  jobs; a signed, multi-architecture (`linux/amd64` + `linux/arm64`)
  release image with SPDX and CycloneDX SBOMs, build provenance, and
  keyless Sigstore/Cosign signing, published as `:X.Y.Z`, `:X.Y`, `:X`,
  and `:latest` (the last withheld from prerelease tags) on every signed
  `v*` tag.

### Security

- Per-request nonce'd Content-Security-Policy, `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, and conditional
  `Strict-Transport-Security` on every response.
- Structural filesystem containment, RCON bounds, and secret redaction
  verified end-to-end (not only unit-level) in
  `tests/Integration/Security/`.
- All mutations pass through one audited `Operation` lifecycle; MCP and
  API principals are structurally incapable of self-approving a
  proposal they created (see `docs/security/threat-model.md`).

### Known limitations

Disclosed here rather than left implicit — see
`docs/operations/v1-acceptance.md` and `docs/security/threat-model.md`
for the full detail behind each:

- Secret redaction is **value-based**: only secrets CraftKeeper already
  knows about (stored `Secret` rows and schema-flagged config fields)
  are scrubbed from outgoing AI requests, logs, and audit metadata. A
  credential that has never been entered into CraftKeeper cannot be
  redacted, because CraftKeeper has no way to recognize it as one.
- Log tailing has a narrow race window across file rotation/truncation
  where a handful of lines can be skipped rather than double-read; see
  `App\Server\LogTailService`'s own inode/offset-tracking docblock.
- Minecraft's own console output is broadcast to the admin-only realtime
  channel **verbatim** — it is not secret-redacted, since it is
  free-form third-party stdout CraftKeeper does not control the shape
  of (a deliberate, documented boundary, not an oversight).
- Plugin install and update are exposed through the web UI only — not
  yet part of `/api/v1` or the MCP tool surface (disable/remove are).
- There is no self-service, web-based backup **restore** — restoring
  means placing a downloaded archive's `database.sqlite` into a fresh
  `/data` and restarting the container (see
  `docs/operations/recovery.md`).
- The Playwright end-to-end suite runs Chromium only; Firefox/WebKit
  projects are a one-line config addition once run in an environment
  with registry access to fetch those browser binaries.
- The four accent themes (terracotta/emerald/slate/bronze) are
  implemented and manually reachable via the design-system page, but do
  not yet have dedicated per-accent automated axe/contrast coverage.
- The AI provider adapter does not yet distinguish a 401, a 429, and a
  timeout into separate reported states — all three currently collapse
  to the same generic "unavailable" signal.
- Hangar and Modrinth response-shape handling is verified against
  recorded fixtures, not against a live call to either service made as
  part of this repository's own test/CI runs.
- `/api/v1`'s cursor pagination is bounded: `OperationController::index()`
  and `ConfigController::listProposals()` materialize at most the most
  recent 1000 rows before paginating in memory. Past that ceiling,
  `has_more: false` is indistinguishable from genuine end-of-data — a
  silent cap this project's own principles would otherwise flag, so it is
  disclosed here explicitly rather than left implicit.
