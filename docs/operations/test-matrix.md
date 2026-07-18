# CraftKeeper release compatibility matrix

Task 20's required, verbatim 10-dimension test matrix. Every row lists
required values; not every value has a dedicated automated test in every
tool (unit/feature/integration/e2e) — where a value is covered, the
covering test/suite is named directly below the table so this document
stays a map to real coverage rather than an aspirational list.

| Dimension | Required values |
|---|---|
| Browser | Current Chromium, Firefox, WebKit |
| Viewport | 1440×1000, 768×1024, 390×844 |
| Theme | Dark/light; terracotta/emerald/slate/bronze |
| Minecraft filesystem | Empty, Paper only, Paper+Geyser+Floodgate, plugin-heavy, read-only |
| RCON | Disabled, bad credentials, unavailable, healthy, delayed, oversized response |
| AI | Disabled, Ollama healthy/down, OpenAI-compatible healthy/401/429/timeout |
| Catalog | All healthy, one source down, stale cache, invalid metadata |
| Config | Valid, malformed, external edit conflict, secret values, restart required |
| Plugin | Manual, catalog, hash mismatch, incompatible, missing dependency, rollback |
| API/MCP | Missing/valid/wrong scope, expired/revoked, idempotent retry |

## Coverage map

### Browser

`tests/e2e/**/*.spec.ts` — `playwright.config.ts`'s `projects` array
defines the browser engines actually exercised. **Only `chromium` is
currently configured** (one project, `devices['Desktop Chrome']`). Firefox
(`devices['Desktop Firefox']`) and WebKit (`devices['Desktop Safari']`)
are one-line `projects` additions Playwright ships built-in support for,
deliberately not turned on in this task: this sandbox's Playwright
install only has Chromium's browser binary downloaded (`npx playwright
install firefox webkit` was not run — no network egress to the
Playwright CDN is available here), so adding those projects without the
binaries would make every e2e spec fail to launch, not exercise a second
engine. **Follow-up, not done in this task:** add `firefox`/`webkit`
projects to `playwright.config.ts` and run `npx playwright install
firefox webkit` in an environment with registry access (e.g. CI).

### Viewport

`tests/e2e/design-system.spec.ts`'s `VIEWPORTS` constant (desktop
1440×1000, tablet 768×1024, mobile 390×844) is the canonical set — every
value in this row, verbatim. Reused by name across the suite (e.g.
`tests/e2e/settings-and-integrations.spec.ts`, `tests/e2e/
server-operations.spec.ts`) rather than re-declared, so a future
breakpoint change is a one-file edit.

### Theme

`tests/e2e/design-system.spec.ts`'s new (Task 20) "token contrast" test
exercises dark and light explicitly (clicking the theme toggle,
asserting `<html data-theme>` flips) and asserts computed WCAG contrast
in both. All four accents (terracotta/emerald/slate/bronze) are toggled
and axe-scanned by the pre-existing `AccentPicker`/accent-button
assertions already in that spec file and by
`resources/js/pages/DesignSystem.tsx`'s own live accent switcher, which
every viewport/axe test above renders through. Task 20's own contrast
audit (see `docs/architecture/decisions.md`) additionally computed every
`--ck-*` foreground/background pair BY HAND for both themes (not just
observed via axe) — axe alone cannot always catch a marginal contrast
failure depending on which DOM node it samples, which is exactly what
let the four fixed pairs go unnoticed across Tasks 3/9/12/19.

### Minecraft filesystem

- **Empty** — `tests/Unit/Filesystem/MinecraftPathTest.php`'s
  `MinecraftRootUnavailable` cases; `tests/Integration/Filesystem/
  ConfigDiscoveryServiceTest.php`'s empty-root case.
- **Paper only** — `tests/fixtures/minecraft` (the git-tracked default
  fixture nearly every Feature/e2e test already uses) IS this case.
- **Paper+Geyser+Floodgate** — `tests/fixtures/minecraft-paper-geyser-floodgate/`
  (Task 20, new — see below) is the dedicated fixture for this exact
  value, consumed by `docker-compose.integration.yml`'s shared volume.
- **Plugin-heavy** — `tests/fixtures/minecraft/plugins/` already contains
  multiple plugins (Task 15's own fixture set); `tests/Feature/Plugins/
  PluginInventoryServiceTest.php` covers scanning many at once.
- **Read-only** — `tests/Integration/Filesystem/LocalMinecraftFilesystemTest.php`/
  `AtomicFileWriterTest.php`'s permission-denied cases (a root/file
  `chmod`-ed unwritable inside a disposable `Tests\Support\
  TempMinecraftRoot`).

### RCON

- **Disabled** — no `rcon.host`/`rcon.password` `Setting`/`Secret`
  configured; `App\Server\ServerStatusService`'s "RCON not configured"
  path (`tests/Feature/Server/ServerStatusServiceTest.php`).
- **Bad credentials** — `App\Console\Exceptions\RconAuthFailed`
  (`tests/Unit/Console/MinecraftRconClientTest.php`'s
  `FakeRconTransport`-driven auth-rejection case).
- **Unavailable** — connection-refused/timeout
  (`RconConnectionClosed`/`RconTimeout`, same test file).
- **Healthy** — the default fixture path every RCON-dependent
  Feature/e2e test exercises.
- **Delayed** — `RetryBackoff`'s jittered-backoff-once-unreachable
  behavior (`tests/Unit/Server/RetryBackoffTest.php`), and
  `MinecraftRconClient::READ_TIMEOUT_SECONDS` bounding a slow server.
- **Oversized response** — `RconResponseTooLarge`
  (`MAX_RESPONSE_BYTES`/`MAX_RESPONSE_PACKETS`, exercised by
  `tests/Unit/Console/MinecraftRconClientTest.php`'s flood/oversized
  cases).

  Task 10's own ambiguity resolution keeps RCON's protocol-level
  correctness **unit-verified only** against `FakeRconTransport` — a real
  server's actual auth handshake is exactly what Task 20's opt-in
  `tests/Integration/Runtime/LegendaryStackSmokeTest.php` (see below)
  exists to additionally exercise.

### AI

`tests/Feature/Ai/AiUnavailableTest.php` covers disabled (no provider
configured) and unreachable/offline (Ollama and hosted alike — the
health-check transport fails) without affecting `/up` or any other page.
`tests/Unit/Ai/AiManagerTest.php` covers Ollama healthy vs down via a
fake health-check transport. `tests/Feature/Ai/
AiRedactionAndInjectionTest.php` covers the OpenAI-compatible-provider
healthy path via `MockHttpClient` (also this task's own
`tests/Integration/Security/SecretLeakTest.php`, reusing the identical
mock pattern). **Gap, not closed by this task:** `App\Ai\Providers\
AbstractAiProvider` does not currently distinguish 401 vs 429 vs a
timeout into different reported states — any provider-side failure
(including a deliberate 401/429 response) collapses to the same generic
"unavailable/degraded" signal `AiUnavailableTest.php` already covers, so
there is no dedicated test asserting a 429 is reported any differently
than a timeout. Worth a future, narrower task if per-status-code
UI/retry behavior is ever added.

### Catalog

`tests/Feature/Catalog/UnifiedCatalogServiceTest.php` covers all-healthy,
one-source-down (partial result + per-source health), stale-cache
fallback (`page_fresh_minutes`/`retention_days`, `config/catalog.php`),
and invalid-metadata (schema-validated against `resources/catalog/
plugin-catalog.schema.json`, `tests/Contract/Catalog/
PluginCatalogContractTest.php`).

### Config

Valid/malformed: `tests/Feature/Config/ConfigChangeServiceTest.php`.
External edit conflict: `App\Config\Exceptions\ConfigConflict`
(`tests/Feature/Config/ConfigApplyHandlerTest.php`). Secret values:
`App\Config\ConfigDiffBuilder::redactSecrets()`
(`tests/Feature/Api/V1/ConfigApiTest.php`, and Task 20's own
`tests/Integration/Security/SecretLeakTest.php`). Restart required:
`RestartRequired`/`App\Models\Setting` restart-pending flag
(`tests/Feature/Config/ConfigApplyHandlerTest.php`).

### Plugin

Manual/catalog: `tests/Feature/Plugins/PluginUploadServiceTest.php` /
`PluginDownloaderTest.php`. Hash mismatch:
`App\Plugins\Exceptions\PluginChecksumMismatch`
(`tests/Feature/Plugins/PluginDownloaderTest.php`). Incompatible/missing
dependency: `App\Plugins\PluginCompatibilityService`
(`tests/Unit/Plugins/PluginCompatibilityServiceTest.php`). Rollback:
`tests/Feature/Plugins/PluginLifecycleServiceTest.php`'s rollback case,
plus `docker-compose.integration.yml`'s "plugin upload/update/rollback"
scenario at the container level.

### API/MCP

Missing/valid/wrong scope: `tests/Feature/Api/V1/ApiScopeTest.php`,
`tests/Feature/Mcp/McpAuthorizationTest.php`. Expired/revoked:
`McpAuthorizationTest.php`'s `->revoked()`/`->expired()`
`McpGrant` factory states. Idempotent retry: `App\Support\Api\
Exceptions\IdempotencyKeyConflict`
(`tests/Feature/Api/V1/OperationApiTest.php`).

## Where this task's own new fixtures/services fit

- `tests/fixtures/minecraft-paper-geyser-floodgate/` — the "Paper +
  Geyser + Floodgate" filesystem value, purpose-built for
  `docker-compose.integration.yml`'s shared volume (a real, disposable
  Minecraft directory a fake-but-protocol-correct RCON service and a
  real CraftKeeper container both mount).
- `docker-compose.integration.yml` — see
  `docs/architecture/decisions.md` and the compose file's own header
  comment for the full scenario list it drives end-to-end without a
  production server.
- `tests/Integration/Runtime/LegendaryStackSmokeTest.php` — opt-in only
  (env-flag-gated, skipped by default); the one place this matrix's RCON
  row is verified against a REAL Paper+Geyser+Floodgate server's actual
  auth handshake rather than a fake transport.
