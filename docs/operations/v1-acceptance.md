# V1 acceptance: Global Constraint traceability

Task 21's release-candidate acceptance requires that **every** Global
Constraint in `docs/superpowers/plans/2026-07-17-craftkeeper-v1.md`
maps to either a passing automated test (named below) or a documented
operator behavior (linked below). This is that mapping, verified against
the actual source at the time of Task 21, not asserted from memory of
what earlier tasks intended to build.

Where a constraint has no single dedicated test but is exercised
incidentally across many, the table names the most direct one and says
so rather than overclaiming a one-to-one mapping that doesn't exist.

| # | Global Constraint | Verification |
|---|---|---|
| 1 | Product name: CraftKeeper | `tests/Feature/BootTest.php` ("serves the CraftKeeper application", asserts the string appears on `/`); documented throughout `README.md`, `composer.json` (`description`), `.env.example` (`APP_NAME`) |
| 2 | Product tagline: "The open-source Minecraft server control plane." | Documented operator-facing fact, not independently asserted in-app: `README.md`'s own tagline line. **Disclosed gap:** no automated test asserts the tagline string renders on a specific in-app page (e.g. login/onboarding welcome) — it is a documentation/branding fact, not a functional behavior, so a dedicated UI test was judged out of proportion to the constraint. |
| 3 | License: AGPL-3.0-or-later; paid/donor support may be offered separately | `LICENSE` (unmodified GNU AGPLv3 text); `composer.json`'s `"license": "AGPL-3.0-or-later"`; `README.md`'s License section; `SECURITY.md`'s "Reporting a vulnerability" section distinguishes security reports from support requests |
| 4 | Manages exactly one Minecraft server via mounted `/minecraft` + RCON; never requires the Docker socket | `docs/security/threat-model.md`'s structural-guarantees list; `ci.yml`'s `container` job asserts `docker.sock` appears in neither `Dockerfile` nor `compose.example.yml`; `docker-compose.integration.yml`'s 10-scenario stack never mounts the socket; documented explicitly in `docs/installation/docker-compose.md` and `README.md` |
| 5 | CraftKeeper state under `/data`; Minecraft files under `/minecraft` | `config/craftkeeper.php`'s `data_root`/`minecraft_root`; `tests/Feature/HealthTest.php` (`/up`'s `data_directory` check); `App\Filesystem\MinecraftPath` canonicalizes exclusively against `minecraft_root`; documented in `docs/installation/docker-compose.md` |
| 6 | Docker Compose behind Dokploy HTTPS; app exposes HTTP on `:8080` | `Dockerfile`'s `EXPOSE 8080` + `HEALTHCHECK`; `compose.example.yml`; `ci.yml`'s `container` job builds and probes this; full proxy/TLS/websocket/trusted-proxy setup documented in `docs/installation/dokploy.md` |
| 7 | Local username/password auth with optional TOTP; registration only during first-run onboarding | `tests/Feature/Auth/OnboardingTest.php` ("allows creation of exactly one administrator" — a second registration attempt 404s); `tests/Feature/Auth/TwoFactorChallengeTest.php`; `tests/Feature/Auth/RegistrationTest.php` |
| 8 | AI is optional; Ollama and OpenAI-compatible providers supported; unavailable providers degrade cleanly | `tests/Feature/Ai/AiUnavailableTest.php`; `tests/Unit/Ai/AiManagerTest.php`; `docs/operations/test-matrix.md`'s "AI" row (disabled/Ollama down/hosted 401 all covered — 401 vs 429 vs timeout distinction is a disclosed gap, see `CHANGELOG.md`) |
| 9 | Umami optional, disabled by default, never blocks rendering/onboarding/requests/builds | `tests/Feature/Settings/AnalyticsTest.php`-style coverage described in `docs/architecture/decisions.md` (Task 19: never-configured/incomplete/insecure-URL all assert zero `<script>` tag); `tests/e2e/settings-and-integrations.spec.ts`'s disabled-by-default assertion; "never blocks builds" is structural — neither `composer.json` nor `package.json` has ever gained an analytics SDK dependency, so no build step can depend on Umami being reachable |
| 10 | Hosted AI requests redact discovered secrets before transmission and disclose exactly what was redacted | `tests/Feature/Ai/AiRedactionAndInjectionTest.php`; `tests/Integration/Security/SecretLeakTest.php`'s "AI transport body" case; `App\Ai\RedactionDisclosure` (the disclosure-to-the-operator half) |
| 11 | AI/REST/MCP use the same application services and policies as the UI; no integration writes directly to the filesystem or RCON transport | `docs/security/threat-model.md`'s "Only services create Operations" structural guarantee; every mutation path (`App\Http\Controllers\ConfigController`, `App\Http\Controllers\Api\V1\*`, `App\Mcp\Tools\*`, `App\Ai\*`) is verified to route through `App\Operations\OperationService`/`App\Config\ConfigChangeService`/`App\Console\RconCommandService` — never a bare `File::put()` or raw socket write from a controller/tool |
| 12 | AI suggests; a human approves. Autonomous actions are out of V1 | `tests/Feature/Mcp/McpAuthorizationTest.php` (no `approve_operation` tool exists at all — `tests/Contract/Mcp/McpCapabilityTest.php` locks the 3-tool list byte-for-byte); `App\Operations\OperationService::approve()`'s `User`-typed second parameter structurally rejects any AI/MCP/API caller |
| 13 | API tokens and MCP grants are scoped; read never implies write/RCON access | `tests/Feature/Api/V1/ApiScopeTest.php`; `tests/Feature/Mcp/McpAuthorizationTest.php`; documented for operators in `docs/operations/api-and-mcp.md` |
| 14 | All config writes: path containment, optimistic concurrency, validation, snapshot, atomic replacement, audit event | `tests/Unit/Filesystem/MinecraftPathTest.php` (containment); `tests/Feature/Config/ConfigConflictTest.php` (optimistic concurrency — stale SHA-256 → 409); `tests/Unit/Config/Formats/*Test.php` (validation); `tests/Integration/Filesystem/AtomicFileWriterTest.php` (snapshot + atomic rename); `tests/Feature/Config/ConfigChangeServiceTest.php` (one audit event per approved change) |
| 15 | No NBT, world region, player data, or arbitrary binary file editing | `tests/Integration/Filesystem/ConfigDiscoveryServiceTest.php` (excludes `world*`, `playerdata`, `stats`, `advancements`, binaries, and files over 2 MiB by construction — `App\Config\ConfigDiscoveryService::ROOT_ONLY_IGNORED_SEGMENTS`) |
| 16 | Do not follow symlinks outside `/minecraft` | `tests/Unit/Filesystem/MinecraftPathTest.php`'s symlink-escape cases; `tests/Integration/Security/FilesystemBoundaryTest.php` (drives the same rejection through the web editor, REST API, and an MCP resource — three independent real callers) |
| 17 | Plugin sources: CraftKeeper Catalog, Hangar, Modrinth, manual JAR upload | `tests/Feature/Catalog/UnifiedCatalogServiceTest.php`; `tests/Feature/Plugins/PluginDownloaderTest.php`; `tests/Feature/Plugins/PluginUploadServiceTest.php` |
| 18 | Plugin install verifies hashes, parses JAR metadata, quarantines, supports rollback, marks restart-required | `App\Plugins\JarInspector` + `tests/Feature/Plugins/PluginDownloaderTest.php`'s hash-mismatch case (`PluginChecksumMismatch`); `tests/Feature/Plugins/PluginLifecycleServiceTest.php`'s rollback case; `docker-compose.integration.yml`'s real-JAR install/update/rollback scenario |
| 19 | Config formats: properties, YAML, JSON, TOML; recognized files get schema-guided editing, all supported text files get source editing+validation | `tests/Unit/Config/Formats/PropertiesAdapterTest.php`, `YamlAdapterTest.php`, `JsonAdapterTest.php`, `TomlAdapterTest.php`; `tests/Unit/Config/Schemas/ConfigSchemaRegistryTest.php`; guided/structured/source mode convergence in `tests/Feature/Http/ConfigControllerTest.php` |
| 20 | WCAG 2.2 AA; status never relies on color alone | `resources/js/components/craftkeeper/StatusBadge.test.tsx` (icon/shape + label + color, `role="status"` with an accessible name); `tests/e2e/design-system.spec.ts`'s axe scans (dark AND light) and hand-verified contrast-ratio table in `docs/architecture/decisions.md` (Task 20 + Task 20 fix pass — all 24 tone×surface×theme pairs ≥4.5:1) |
| 21 | Default theme dark; variants light/terracotta/emerald/slate/bronze | `resources/css/app.css`'s `[data-theme]`/`[data-accent]` variants; `resources/js/pages/DesignSystem.tsx`'s live `AccentPicker`; `tests/e2e/design-system.spec.ts`'s dark/light toggle test. **Disclosed gap** (`docs/operations/test-matrix.md`): the four accent variants are implemented and manually reachable but have no dedicated per-accent automated axe/contrast test yet |
| 22 | Hanken Grotesk (UI); JetBrains Mono (paths/code/config/logs/console) | `resources/css/app.css`'s `--ck-font-sans`/`--ck-font-mono` custom properties, loaded via `@fontsource/hanken-grotesk`/`@fontsource/jetbrains-mono` with `font-display: swap`; consumed by `SourceEditor.tsx`/console/log components. Task 21 confirmed this live against a running instance: `getComputedStyle(document.body).fontFamily` resolves to `"Hanken Grotesk", ui-sans-serif, system-ui, ...` at `/overview`. **Disclosed gap:** that live check was a one-time manual spot-check (see `docs/architecture/decisions.md`, Task 21), not wired into an automated regression test — a future test asserting computed `font-family` would close this permanently |
| 23 | Breakpoints 480/768/1024px; desktop content max-width 1160px; sidebar 236px | `resources/js/layouts/AppShell.tsx` (`lg:w-[236px]`, `max-w-[1160px]`); `tests/e2e/design-system.spec.ts`'s `VIEWPORTS` constant (1440×1000/768×1024/390×844) exercises the 768/1024 breakpoints directly (no-horizontal-scroll assertions at each). Task 21 confirmed live: at 1440×1000 the sidebar `<nav>`'s computed `getBoundingClientRect().width` is exactly `236` and `<main>`'s is exactly `1160`; at 768px the sidebar is not visible and there is no horizontal scroll; at 390px there is no horizontal scroll and the skip-to-content link is present. **Disclosed gap:** the 480px breakpoint specifically, and a permanent automated (not one-time manual) assertion of the exact 1160/236 pixel values, remain source-inspection-verified rather than test-asserted |
| 24 | Use the exact `--ck-*` tokens from `Design/handoff/design-tokens.json`; no second token vocabulary | `resources/css/app.css` maps every handoff key to a `--ck-*` custom property; `resources/js/lib/ck-tokens.ts` is the single place component-level token math (e.g. chip fill percentages) lives — no component defines a raw hex literal outside this file and `app.css` (spot-checked during Task 20's contrast audit, which is exactly what found and fixed the token-math bugs recorded in `docs/architecture/decisions.md`) |
| 25 | Every user-visible operation has idle/pending/success/failure/degraded/retry behavior | `resources/js/features/operations/OperationProgress.tsx` + `App\Operations\OperationStatus` enum (`Proposed`/`Approved`/`Running`/`Succeeded`/`Failed`/`Rejected`/`RolledBack`) drives this uniformly; `tests/Browser/ServerOperationsTest.php` and `tests/e2e/*` exercise pending/consequence/approval states; degraded states are covered per-integration (RCON, AI, catalog — see `docs/security/threat-model.md`'s trust-boundary table, each with its own isolated-degradation test) |
| 26 | DRY, YAGNI, TDD, focused files, one reviewable commit per task | Documented practice, not a single test: `docs/architecture/decisions.md`'s 21 dated sections, each ending in a "Gates, run for real" block with actual command output; `git log --oneline` on `craftkeeper-v1` shows one commit per task (verify with `git log --oneline main..craftkeeper-v1`) |

## How to re-verify this table

Every named test file can be re-run directly, e.g.:

```bash
php artisan test tests/Feature/Auth/OnboardingTest.php
php artisan test tests/Unit/Filesystem/MinecraftPathTest.php
php artisan test tests/Integration/Security/FilesystemBoundaryTest.php
npm run test -- StatusBadge
npm run e2e -- --grep "design system"
```

or the full suite via the gates in `CONTRIBUTING.md` / `composer test` /
`npm run test` / `npm run e2e`.

## Disclosed gaps, collected

The individual rows above call these out inline; collected here so they
aren't easy to miss:

- No dedicated in-app assertion of the exact tagline string (constraint 2).
- No per-accent (terracotta/emerald/slate/bronze) automated axe/contrast
  test (constraint 21) — manually reachable, and the default
  (terracotta) accent was live-spot-checked in Task 21, but the other
  three remain unautomated and unreviewed this task.
- No permanent automated live-DOM `font-family` computed-style assertion
  (constraint 22) — Task 21 confirmed the value live, once, manually;
  no regression test asserts it going forward.
- The 480px breakpoint specifically, and a permanent automated (rather
  than one-time manual) assertion of the exact 1160px/236px pixel
  values, remain source-inspection/manual-spot-check-verified rather
  than test-asserted (constraint 23) — 768px/390px and the 236px/1160px
  values themselves WERE confirmed live in Task 21 (see
  `docs/architecture/decisions.md`).
- `resources/css/app.css`'s `@media (prefers-reduced-motion: reduce)`
  block is implemented but has no automated e2e coverage (`grep -rn
  reduced-motion tests/e2e/` returns nothing) and could not be verified
  live in Task 21 either — the browser-automation session used exposed
  no way to emulate the `prefers-reduced-motion` media feature.
- A full pixel-level visual comparison of every route against its
  `Design/*.dc.html` mockup, a full keyboard-only traversal of every
  approval/diff/bottom-sheet flow, verification at 200% browser zoom,
  and a visual/contrast review of the three non-default accent themes
  have not been performed by any automated tool or agent session to
  date — these need a human reviewer with visual judgment. See
  `docs/architecture/decisions.md`'s Task 21 entry for exactly what WAS
  automated/spot-checked instead.

None of these gaps involve a security or approval boundary — they are
presentation-detail assertions a future task (or a human design-review
pass) can add or perform, mirroring the pattern
`tests/e2e/design-system.spec.ts` already establishes for dark/light
contrast, rather than anything this release's functional acceptance
depends on.
