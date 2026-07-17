# CraftKeeper V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (<code>- [ ]</code>) syntax for tracking.

**Goal:** Build and release CraftKeeper, an AGPL-licensed, Docker-native Minecraft server control plane that turns a mounted server directory into a safe configuration, plugin, RCON, AI, REST API, and MCP management experience.

**Architecture:** CraftKeeper is a single Laravel 13 application with an Inertia 3 / React 19 interface, SQLite persistence, database queues, Laravel Reverb events, and a least-privilege bind to <code>/minecraft</code>. UI, REST API, MCP tools, and AI tools all call the same application services and authorization policies; every mutation is represented by a proposal or operation, validated, snapshotted, audited, written atomically, and made reversible where the underlying operation permits it.

**Tech Stack:** PHP 8.4, Laravel 13, Inertia 3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Pest, Vitest, React Testing Library, Playwright, SQLite, Laravel Fortify, Sanctum, Passport, Laravel MCP, Laravel Reverb/Echo, php-agents, Nginx, PHP-FPM, Supervisor, Docker Buildx, GitHub Actions, GHCR.

## Global Constraints

- Product name: CraftKeeper.
- Product tagline: “The open-source Minecraft server control plane.”
- License: AGPL-3.0-or-later; source is free, while paid and donor support may be offered separately.
- V1 manages exactly one Minecraft server through a mounted <code>/minecraft</code> directory and RCON; it must never require Docker socket access.
- CraftKeeper state lives under <code>/data</code>; Minecraft files remain under <code>/minecraft</code>.
- Deployment target is Docker Compose behind Dokploy HTTPS; the application container exposes HTTP on port 8080.
- Authentication is local username/password with optional TOTP; registration is available only during first-run onboarding.
- AI is optional. OpenAI-compatible providers and Ollama are supported; unavailable providers must degrade cleanly without affecting non-AI features.
- Umami analytics is optional, disabled by default, and must never block rendering, onboarding, requests, or builds.
- Hosted AI requests redact discovered secrets before transmission and disclose exactly what was redacted.
- AI, REST, and MCP use the same application services and policies as the UI; no integration may write directly to the filesystem or RCON transport.
- AI suggests changes and actions; a human approves them. Autonomous actions are explicitly outside V1.
- API tokens and MCP grants are scoped. Read access does not imply configuration write or RCON access.
- All configuration writes use path containment, optimistic concurrency, validation, a snapshot, atomic replacement, and an audit event.
- No NBT, world region, player data, or arbitrary binary file editing in V1.
- Do not follow symlinks outside <code>/minecraft</code>.
- Plugin sources are CraftKeeper Catalog, Hangar, Modrinth, and manual JAR upload.
- Plugin installation verifies hashes, parses JAR metadata, stages in quarantine, supports rollback, and clearly marks restart-required operations.
- Supported config formats are properties, YAML, JSON, and TOML. Recognized files get schema-guided editing; all supported text files get source editing and validation.
- Accessibility target is WCAG 2.2 AA. Status must never rely on color alone.
- Default theme is dark. Theme variants are light, terracotta, emerald, slate, and bronze.
- Hanken Grotesk is the UI font; JetBrains Mono is used for paths, code, configuration source, logs, and console output.
- Design breakpoints are 480px, 768px, and 1024px; desktop content max-width is 1160px and desktop sidebar width is 236px.
- Use the exact <code>--ck-*</code> tokens in <code>Design/handoff/design-tokens.json</code>; do not introduce a second token vocabulary.
- Every user-visible operation has idle, pending, success, failure, degraded, and retry behavior.
- DRY, YAGNI, test-driven development, focused files, and one reviewable commit per task.

---

## Design Review and Product Decisions

The files under <code>Design/</code> are the visual source of truth. The fourteen desktop/mobile mockups and the structured handoff agree on a calm, dense operational interface: warm dark surfaces, restrained terracotta accent, thin separators, compact status chips, consequence-first copy, and progressive disclosure instead of decorative dashboards.

Implementation must preserve these decisions:

- Build one <code>AppShell</code>; do not copy the sidebar/header markup from each mockup.
- Primary navigation is Overview, Server, Configurations, Plugins, Assistant, Activity, Integrations, and Settings.
- Provenance is always visible: “Built in,” “Plugin,” “Discovered,” “Catalog,” “Hangar,” “Modrinth,” or “Manual.”
- Risk and status always combine icon/shape, label, and color.
- Configuration editing uses guided, structured, and source modes with a shared diff/review flow.
- Destructive and high-risk actions show consequences before the confirmation control.
- Desktop split panes become drawers or bottom sheets on mobile. Tables become stacked cards below 768px.
- The command palette, contextual assistant drawer, approval panel, operation progress, and restart-required banner are shared primitives.
- Server, AI, catalog source, RCON, and documentation-source failures are isolated degraded states rather than whole-page failures.
- Empty states explain how to resolve the condition; errors retain enough context for diagnosis and retry.

### Pages covered by the mockups

- Login and first-run onboarding
- Overview
- Server detail, players, Console, and Logs
- Configuration inventory and Config Editor
- Plugin discovery
- Assistant
- Activity
- Integrations
- Settings
- Responsive navigation, console, configuration editor, and approval sheet
- Design kit/component states

### Required V1 pages not individually mocked

Use the components and page grammar in <code>Design/handoff/components.json</code> and <code>Design/handoff/pages.json</code> for:

- Configuration conflict resolution, save result, history, revision detail, and restore
- Installed plugins, plugin detail, install/update plan, manual JAR upload, disable/remove, and pending operations
- Contextual assistant drawer, AI RCON composer, and AI-unavailable states
- Snapshot browser, backup state, diagnostics, and support bundle
- API token detail, OpenAPI documentation, MCP grant/capability detail, and catalog source detail
- Security, AI provider, analytics, appearance, backup, API, MCP, and advanced settings sections

## System Boundaries

~~~text
Browser / REST client / MCP client
                 |
       Laravel routes + policies
                 |
 Application services and Operations
      /          |           \
 Config      Plugin       Server/RCON
 service     service       service
      \          |           /
       Snapshot + Audit + Events
                 |
        /minecraft and /data
~~~

Trust boundaries:

1. Browser input, API input, MCP input, catalog metadata, uploaded JARs, log lines, plugin configs, and AI output are untrusted.
2. Only application services may create an <code>Operation</code>.
3. Only an approved operation may invoke a mutation port.
4. Filesystem ports enforce canonical containment beneath <code>/minecraft</code>.
5. RCON ports enforce timeouts, maximum response size, safe-command policy, and audit logging.
6. External AI never receives raw values classified as secrets.
7. MCP clients cannot approve proposals they created.

## Repository and File Map

The Laravel application lives at the CraftKeeper repository root beside <code>Design/</code> and <code>docs/</code>.

~~~text
app/
  Ai/                    Provider adapters, context assembly, redaction, tools
  Catalog/               Catalog contracts and source clients
  Config/                Discovery, format adapters, schemas, validation
  Console/               RCON protocol, command policy, history
  Filesystem/            Canonical paths, atomic writes, snapshots
  Http/Controllers/      Inertia and REST controllers only
  Mcp/                   MCP resources, prompts, tools, authorization
  Models/                Eloquent persistence models
  Operations/            Proposal, approval, execution, rollback
  Plugins/               JAR inspection and lifecycle orchestration
  Server/                Health, players, logs, server state
  Support/               Audit, encryption, diagnostics, shared value objects
bootstrap/
config/
database/
  factories/
  migrations/
  seeders/
docker/
  nginx/default.conf
  supervisor/supervisord.conf
docs/
  architecture/
  operations/
  security/
  superpowers/plans/
public/
resources/
  css/app.css
  js/
    components/
    features/
    layouts/
    lib/
    pages/
    types/
  schemas/config/
  catalog/plugin-catalog.schema.json
routes/
  api.php
  console.php
  mcp.php
  web.php
tests/
  Browser/
  Feature/
  Integration/
  Unit/
  fixtures/
Dockerfile
compose.example.yml
openapi.yaml
~~~

## Stable Interfaces

These contracts are fixed early so tasks can be implemented and reviewed independently.

~~~php
interface MinecraftFilesystem
{
    public function discover(): array; // list<DiscoveredFile>
    public function read(MinecraftPath $path): FileSnapshot;
    public function writeAtomically(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot;
    public function copyToSnapshot(MinecraftPath $path, string $operationId): SnapshotReference;
}

interface ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool;
    public function parse(string $contents): ParsedConfig;
    public function validate(string $contents, ?ConfigSchema $schema): ValidationResult;
    public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string;
}

interface RconClient
{
    public function execute(RconCommand $command): RconResponse;
}

interface PluginSource
{
    public function search(PluginSearchQuery $query): PluginSearchPage;
    public function release(PluginReleaseId $id): PluginRelease;
}

interface OperationHandler
{
    public function supports(OperationType $type): bool;
    public function execute(Operation $operation): OperationResult;
    public function rollback(Operation $operation): OperationResult;
}

interface AiProvider
{
    public function health(): AiProviderHealth;
    public function stream(AiRequest $request): iterable;
}
~~~

Canonical operation types:

~~~php
enum OperationType: string
{
    case ConfigApply = 'config.apply';
    case ConfigRestore = 'config.restore';
    case PluginInstall = 'plugin.install';
    case PluginUpdate = 'plugin.update';
    case PluginDisable = 'plugin.disable';
    case PluginRemove = 'plugin.remove';
    case PluginRollback = 'plugin.rollback';
    case RconCommand = 'rcon.command';
    case ServerStop = 'server.stop';
}
~~~

## Scope and Delivery Sequence

This is one product plan, but it has six reviewable milestones:

1. Foundation: Tasks 1–4
2. Safe configuration control plane: Tasks 5–9
3. Server operations and telemetry: Tasks 10–12
4. Plugin lifecycle: Tasks 13–15
5. AI, REST API, and MCP: Tasks 16–18
6. Completion, hardening, and release: Tasks 19–21

Do not begin a milestone until the preceding milestone’s full verification command passes.

### Task 1: Bootstrap Laravel, Quality Gates, and License

**Files:**
- Create: application files at repository root from the Laravel React starter kit
- Create: <code>LICENSE</code>
- Create: <code>composer.json</code>
- Create: <code>package.json</code>
- Create: <code>phpstan.neon</code>
- Create: <code>pint.json</code>
- Create: <code>vitest.config.ts</code>
- Create: <code>playwright.config.ts</code>
- Create: <code>tests/Feature/BootTest.php</code>
- Modify: <code>README.md</code>

**Interfaces:**
- Consumes: PHP 8.4, Node 22, Composer 2.
- Produces: <code>composer test</code>, <code>npm run test</code>, <code>npm run typecheck</code>, and <code>npm run build</code> as stable verification commands.

- [ ] **Step 1: Write the failing boot test**

~~~php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the CraftKeeper application', function () {
    $this->get('/')->assertOk()->assertSee('CraftKeeper');
});
~~~

- [ ] **Step 2: Scaffold the application and install exact capability packages**

Run:

~~~bash
laravel new /tmp/craftkeeper-bootstrap --using=laravel/react-starter-kit --pest --database=sqlite --no-interaction
rsync -a --exclude=.git /tmp/craftkeeper-bootstrap/ ./
composer require laravel/fortify laravel/sanctum laravel/passport laravel/mcp:^0.7 laravel/reverb carmelosantana/php-agents:^0.15 symfony/yaml yosymfony/toml
composer require --dev larastan/larastan
npm install
npm install -D vitest jsdom @testing-library/react @testing-library/jest-dom @playwright/test
~~~

Expected: Composer resolves on PHP 8.4, the React starter kit builds, and no package requires Docker socket access.

- [ ] **Step 3: Add license and quality scripts**

Set Composer scripts to:

~~~json
{
  "test": [
    "@php artisan config:clear",
    "@php artisan test",
    "@php vendor/bin/phpstan analyse --memory-limit=1G",
    "@php vendor/bin/pint --test"
  ]
}
~~~

Set npm scripts to:

~~~json
{
  "test": "vitest run",
  "typecheck": "tsc --noEmit",
  "build": "vite build",
  "e2e": "playwright test"
}
~~~

Copy the unmodified GNU AGPL v3 text into <code>LICENSE</code> and identify the project as <code>AGPL-3.0-or-later</code> in <code>README.md</code> and <code>composer.json</code>.

- [ ] **Step 4: Run the bootstrap gates**

Run:

~~~bash
composer test
npm run test
npm run typecheck
npm run build
~~~

Expected: all commands exit 0 and <code>BootTest</code> passes.

- [ ] **Step 5: Commit**

~~~bash
git add LICENSE README.md app bootstrap composer.json composer.lock config database package.json package-lock.json phpstan.neon pint.json playwright.config.ts public resources routes tests vitest.config.ts
git commit -m "chore: bootstrap CraftKeeper control plane"
~~~

### Task 2: Docker, Dokploy, and Process Runtime

**Files:**
- Create: <code>Dockerfile</code>
- Create: <code>.dockerignore</code>
- Create: <code>compose.example.yml</code>
- Create: <code>docker/nginx/default.conf</code>
- Create: <code>docker/supervisor/supervisord.conf</code>
- Create: <code>docker/entrypoint.sh</code>
- Create: <code>app/Http/Controllers/HealthController.php</code>
- Create: <code>tests/Feature/HealthTest.php</code>
- Modify: <code>routes/web.php</code>
- Modify: <code>config/database.php</code>

**Interfaces:**
- Consumes: application verification commands from Task 1.
- Produces: HTTP <code>GET /up</code>, persistent <code>/data/database.sqlite</code>, Minecraft mount <code>/minecraft</code>, and websocket endpoint <code>/app</code>.

- [ ] **Step 1: Write health and volume contract tests**

~~~php
it('reports application and database readiness', function () {
    $this->getJson('/up')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['checks' => ['database', 'data_directory']]);
});
~~~

- [ ] **Step 2: Build the multi-stage image**

The final image must run PHP 8.4 FPM, Nginx, one database queue worker, <code>schedule:work</code>, and Reverb under Supervisor. It must create <code>/data</code>, never create or chown <code>/minecraft</code>, run application processes as a non-root <code>craftkeeper</code> user, expose 8080, and define:

~~~dockerfile
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl --fail --silent http://127.0.0.1:8080/up || exit 1
~~~

Nginx serves Laravel on 8080 and proxies websocket upgrade requests at <code>/app</code> to Reverb on <code>127.0.0.1:8081</code>.

- [ ] **Step 3: Provide a Dokploy-ready Compose example**

~~~yaml
services:
  craftkeeper:
    image: ghcr.io/carmelosantana/craftkeeper:latest
    restart: unless-stopped
    ports:
      - "8080:8080"
    volumes:
      - minecraft:/minecraft
      - craftkeeper_data:/data
    environment:
      APP_URL: https://craftkeeper.example.com
      APP_KEY: ${CRAFTKEEPER_APP_KEY}
      MINECRAFT_ROOT: /minecraft
      DATA_ROOT: /data
      DB_CONNECTION: sqlite
      DB_DATABASE: /data/database.sqlite
      QUEUE_CONNECTION: database
      CACHE_STORE: database
      SESSION_DRIVER: database
    healthcheck:
      test: ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:8080/up"]
      interval: 30s
      timeout: 5s
      retries: 3

volumes:
  minecraft:
    external: true
  craftkeeper_data:
~~~

- [ ] **Step 4: Verify container behavior**

Run:

~~~bash
docker build -t craftkeeper:test .
docker compose -f compose.example.yml config
docker run --rm craftkeeper:test php -v
~~~

Expected: valid Compose, PHP 8.4.x, image healthcheck present, and no Docker socket mount.

- [ ] **Step 5: Commit**

~~~bash
git add Dockerfile .dockerignore compose.example.yml docker app/Http/Controllers/HealthController.php routes/web.php config/database.php tests/Feature/HealthTest.php
git commit -m "feat: add Docker and Dokploy runtime"
~~~

### Task 3: Design System and Responsive Application Shell

**Files:**
- Create: <code>resources/js/components/ui/</code> shadcn primitives used by the handoff
- Create: <code>resources/js/components/craftkeeper/StatusBadge.tsx</code>
- Create: <code>resources/js/components/craftkeeper/ProvenanceBadge.tsx</code>
- Create: <code>resources/js/components/craftkeeper/RestartRequired.tsx</code>
- Create: <code>resources/js/components/craftkeeper/PageState.tsx</code>
- Create: <code>resources/js/layouts/AppShell.tsx</code>
- Create: <code>resources/js/features/command-palette/CommandPalette.tsx</code>
- Create: <code>resources/js/pages/DesignSystem.tsx</code>
- Create: <code>resources/js/components/craftkeeper/StatusBadge.test.tsx</code>
- Modify: <code>resources/css/app.css</code>
- Modify: <code>tailwind.config.ts</code>

**Interfaces:**
- Consumes: exact tokens and component states in <code>Design/handoff/design-tokens.json</code> and <code>Design/handoff/components.json</code>.
- Produces: <code>StatusBadge</code>, <code>ProvenanceBadge</code>, <code>RestartRequired</code>, <code>PageState</code>, <code>AppShell</code>, and responsive layout primitives used by every page.

- [ ] **Step 1: Write component behavior and accessibility tests**

~~~tsx
it('exposes status without relying on color', () => {
  render(<StatusBadge status="degraded" label="RCON unavailable" />);
  expect(screen.getByText('RCON unavailable')).toBeVisible();
  expect(screen.getByRole('status')).toHaveAccessibleName(/degraded/i);
});
~~~

- [ ] **Step 2: Import the exact visual tokens**

Map every key in <code>Design/handoff/design-tokens.json</code> to a CSS custom property beginning <code>--ck-</code>. Load Hanken Grotesk and JetBrains Mono with <code>font-display: swap</code>. Implement dark/light and four accent variants through <code>data-theme</code> and <code>data-accent</code>, not duplicated stylesheets.

- [ ] **Step 3: Implement the shared shell**

Use this navigation contract:

~~~ts
export const primaryNavigation = [
  'Overview',
  'Server',
  'Configurations',
  'Plugins',
  'Assistant',
  'Activity',
  'Integrations',
  'Settings',
] as const;
~~~

At 1024px and above render the 236px sidebar; below 1024px render the mobile header and navigation drawer. Constrain page content to 1160px. Preserve focus trapping, Escape dismissal, focus return, visible focus rings, and skip navigation.

- [ ] **Step 4: Compare against the design kit**

Run:

~~~bash
npm run test -- StatusBadge
npm run typecheck
npm run build
npm run e2e -- --grep "design system"
~~~

Expected: tests pass at 1440×1000, 768×1024, and 390×844 with no horizontal scroll and no automated accessibility violations.

- [ ] **Step 5: Commit**

~~~bash
git add resources/css resources/js/components resources/js/features/command-palette resources/js/layouts resources/js/pages/DesignSystem.tsx tailwind.config.ts
git commit -m "feat: implement CraftKeeper design system"
~~~

### Task 4: Single-Admin Onboarding, Login, TOTP, and Secrets

**Files:**
- Create: <code>app/Support/InstallationState.php</code>
- Create: <code>app/Http/Middleware/RequireInstallation.php</code>
- Create: <code>app/Http/Controllers/OnboardingController.php</code>
- Create: <code>app/Models/Setting.php</code>
- Create: <code>app/Models/Secret.php</code>
- Create: <code>database/migrations/*_create_settings_table.php</code>
- Create: <code>database/migrations/*_create_secrets_table.php</code>
- Create: <code>resources/js/pages/auth/Login.tsx</code>
- Create: <code>resources/js/pages/onboarding/Index.tsx</code>
- Create: <code>resources/js/pages/settings/Security.tsx</code>
- Create: <code>tests/Feature/Auth/OnboardingTest.php</code>
- Modify: <code>config/fortify.php</code>
- Modify: <code>routes/web.php</code>

**Interfaces:**
- Consumes: Fortify authentication and Task 3 form components.
- Produces: <code>InstallationState::isInstalled(): bool</code>, exactly one admin user, encrypted secrets, optional TOTP, and recovery codes.

- [ ] **Step 1: Write first-run and lockout tests**

~~~php
it('allows creation of exactly one administrator', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ])->assertRedirect('/onboarding/server');

    $this->post('/onboarding/admin', [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'another-long-passphrase',
        'password_confirmation' => 'another-long-passphrase',
    ])->assertNotFound();
});
~~~

- [ ] **Step 2: Implement installation state and encrypted storage**

<code>Secret</code> must use Laravel encrypted casts for <code>value</code>; never include that attribute in serialization, logs, audit metadata, or Inertia props. Disable public registration once a user exists. Rate-limit login to five attempts per minute per normalized email and IP.

- [ ] **Step 3: Implement the mocked onboarding flow**

Steps are Welcome, admin account, Minecraft directory check, RCON setup/test, optional AI provider, optional analytics, and completion. Every optional integration includes “Skip for now.” RCON instructions must explain enabling <code>enable-rcon=true</code>, choosing a strong password, and keeping the RCON port private.

- [ ] **Step 4: Verify authentication**

Run:

~~~bash
php artisan test tests/Feature/Auth
npm run e2e -- --grep "onboarding|login|two-factor"
~~~

Expected: first-run is reachable without login, registration disappears after completion, TOTP recovery works, and secrets never appear in responses.

- [ ] **Step 5: Commit**

~~~bash
git add app/Support/InstallationState.php app/Http/Middleware app/Http/Controllers/OnboardingController.php app/Models/Setting.php app/Models/Secret.php config/fortify.php database/migrations resources/js/pages/auth resources/js/pages/onboarding resources/js/pages/settings/Security.tsx routes/web.php tests/Feature/Auth
git commit -m "feat: add secure single-admin onboarding"
~~~

### Task 5: Persistence, Audit, Operations, and Realtime Events

**Files:**
- Create: <code>app/Models/AuditEvent.php</code>
- Create: <code>app/Models/Operation.php</code>
- Create: <code>app/Models/OperationStep.php</code>
- Create: <code>app/Models/ChangeProposal.php</code>
- Create: <code>app/Operations/OperationService.php</code>
- Create: <code>app/Operations/OperationAuthor.php</code>
- Create: <code>app/Operations/OperationRequest.php</code>
- Create: <code>app/Operations/OperationStatus.php</code>
- Create: <code>app/Events/OperationUpdated.php</code>
- Create: <code>database/migrations/*_create_control_plane_tables.php</code>
- Create: <code>tests/Feature/Operations/OperationServiceTest.php</code>
- Modify: <code>routes/channels.php</code>

**Interfaces:**
- Consumes: authenticated admin from Task 4.
- Produces: <code>OperationService::propose(OperationRequest, OperationAuthor): Operation</code>, <code>approve(string, User): Operation</code>, <code>reject(string, User, string): Operation</code>, and private Reverb channel <code>operations.{id}</code>.

- [ ] **Step 1: Write lifecycle and separation-of-duty tests**

~~~php
it('does not execute a proposed mutation before human approval', function () {
    $operation = app(OperationService::class)->propose(
        OperationRequest::configApply('server.properties', 'expected-sha', ['allow-flight' => 'true']),
        OperationAuthor::mcp('client-1')
    );

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull();
});
~~~

- [ ] **Step 2: Create the canonical operation state machine**

~~~php
enum OperationStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case RolledBack = 'rolled_back';
}
~~~

Reject illegal transitions, store actor type/id, origin, risk, redacted inputs, timestamps, outcome, error code, and correlation ID. Audit events are append-only at the application layer.

- [ ] **Step 3: Broadcast private progress**

Authorize only the admin user. Broadcast sanitized operation progress after database commit. Never broadcast secret fields, RCON passwords, API token values, raw hosted-AI payloads, or uploaded JAR contents.

- [ ] **Step 4: Verify operation invariants**

Run:

~~~bash
php artisan test tests/Feature/Operations
php artisan test --filter=Broadcast
~~~

Expected: all state transitions pass; MCP-authored operations cannot self-approve; failed operations preserve diagnostic codes.

- [ ] **Step 5: Commit**

~~~bash
git add app/Models/AuditEvent.php app/Models/Operation.php app/Models/OperationStep.php app/Models/ChangeProposal.php app/Operations app/Events database/migrations routes/channels.php tests/Feature/Operations
git commit -m "feat: add audited operation lifecycle"
~~~

### Task 6: Contained Minecraft Filesystem and Discovery

**Files:**
- Create: <code>app/Filesystem/MinecraftPath.php</code>
- Create: <code>app/Filesystem/LocalMinecraftFilesystem.php</code>
- Create: <code>app/Filesystem/AtomicFileWriter.php</code>
- Create: <code>app/Filesystem/SnapshotStore.php</code>
- Create: <code>app/Config/ConfigDiscoveryService.php</code>
- Create: <code>tests/Unit/Filesystem/MinecraftPathTest.php</code>
- Create: <code>tests/Integration/Filesystem/AtomicFileWriterTest.php</code>
- Create: <code>tests/fixtures/minecraft/</code>

**Interfaces:**
- Consumes: <code>MINECRAFT_ROOT=/minecraft</code> and <code>DATA_ROOT=/data</code>.
- Produces: the <code>MinecraftFilesystem</code> contract, immutable <code>FileSnapshot</code>, and discovered config inventory.

- [ ] **Step 1: Write path escape and atomicity tests**

~~~php
it('rejects traversal and escaping symlinks', function (string $path) {
    expect(fn () => MinecraftPath::fromUserInput($path))
        ->toThrow(UnsafeMinecraftPath::class);
})->with(['../etc/passwd', '/etc/passwd', "plugins/\0bad.yml"]);
~~~

Also test a symlink inside the fixture root pointing outside it, a changed expected SHA-256, file mode preservation, interrupted temp writes, and cleanup of temporary files.

- [ ] **Step 2: Implement canonical containment**

Normalize separators, reject absolute paths, NUL bytes, traversal components, device names, and any symlink whose resolved target is not under the canonical Minecraft root. Allow only regular files for reads and writes.

- [ ] **Step 3: Implement snapshot and atomic write order**

For every write: acquire a per-path lock, re-read and compare SHA-256, validate new content, snapshot the old bytes under <code>/data/snapshots/{operation-id}/</code>, write and fsync a same-directory temporary file, preserve ownership/mode where permitted, rename atomically, verify the resulting hash, release the lock.

- [ ] **Step 4: Implement discovery**

Discover root properties/config files, Paper configuration directories, Geyser/Floodgate conventional paths, and <code>plugins/*</code>. Ignore <code>logs</code>, <code>world*</code>, <code>playerdata</code>, <code>stats</code>, <code>advancements</code>, hidden backup directories, binary files, files over 2 MiB, and unsupported extensions. Return provenance and recognition state.

- [ ] **Step 5: Verify filesystem security**

Run:

~~~bash
php artisan test tests/Unit/Filesystem tests/Integration/Filesystem
~~~

Expected: traversal, external symlink, stale hash, and partial-write tests fail safely; successful writes are atomic and snapshotted.

- [ ] **Step 6: Commit**

~~~bash
git add app/Filesystem app/Config/ConfigDiscoveryService.php tests/Unit/Filesystem tests/Integration/Filesystem tests/fixtures/minecraft
git commit -m "feat: add safe Minecraft filesystem boundary"
~~~

### Task 7: Configuration Formats, Schemas, and Validation

**Files:**
- Create: <code>app/Config/Formats/PropertiesAdapter.php</code>
- Create: <code>app/Config/Formats/YamlAdapter.php</code>
- Create: <code>app/Config/Formats/JsonAdapter.php</code>
- Create: <code>app/Config/Formats/TomlAdapter.php</code>
- Create: <code>app/Config/ConfigFormatRegistry.php</code>
- Create: <code>app/Config/Schemas/ConfigSchemaRegistry.php</code>
- Create: <code>resources/schemas/config/server-properties.json</code>
- Create: <code>resources/schemas/config/paper-global.json</code>
- Create: <code>resources/schemas/config/geyser.json</code>
- Create: <code>resources/schemas/config/floodgate.json</code>
- Create: <code>tests/Unit/Config/Formats/*Test.php</code>
- Create: <code>tests/Unit/Config/Schemas/ConfigSchemaRegistryTest.php</code>

**Interfaces:**
- Consumes: <code>ConfigFormatAdapter</code> and discovered files from Task 6.
- Produces: <code>ConfigFormatRegistry::for(FileSnapshot): ConfigFormatAdapter</code>, parsed nodes with source locations, validation diagnostics, and schema field metadata.

- [ ] **Step 1: Write format fixtures and round-trip tests**

~~~php
it('patches one properties value without removing comments or reordering keys', function () {
    $source = "# keep this\nallow-flight=false\nmotd=Hello\n";
    $result = (new PropertiesAdapter())->applyChanges($source, [
        ConfigChange::replace('allow-flight', true),
    ], null);

    expect($result)->toBe("# keep this\nallow-flight=true\nmotd=Hello\n");
});
~~~

Cover booleans, integers, nulls, arrays, quoted scalars, duplicate properties, CRLF, YAML anchors rejection, malformed input, and UTF-8 errors.

- [ ] **Step 2: Implement safe adapters**

Properties and recognized scalar YAML/TOML changes must patch original source spans to preserve comments and ordering. JSON may serialize with two-space indentation and a trailing newline. Generic structured saves that cannot preserve comments must display a normalization warning before proposal creation.

- [ ] **Step 3: Define recognized schemas**

Each schema field includes path, type, title, description, default, restart impact, risk, allowed values/range, secret flag, and authoritative documentation URL. Start with the settings displayed in the mockups plus RCON, flight, whitelist, ports, online mode, Geyser remote/auth, Floodgate key path, and Paper gameplay/network settings.

- [ ] **Step 4: Verify adapters**

Run:

~~~bash
php artisan test tests/Unit/Config
~~~

Expected: valid fixtures parse and round-trip as specified; invalid content produces line/column diagnostics without throwing raw parser exceptions into controllers.

- [ ] **Step 5: Commit**

~~~bash
git add app/Config/Formats app/Config/ConfigFormatRegistry.php app/Config/Schemas resources/schemas/config tests/Unit/Config
git commit -m "feat: add validated configuration formats"
~~~

### Task 8: Configuration Proposal, Conflict, Snapshot, and Restore Services

**Files:**
- Create: <code>app/Config/ConfigChangeService.php</code>
- Create: <code>app/Config/ConfigRevisionService.php</code>
- Create: <code>app/Config/ConfigChange.php</code>
- Create: <code>app/Config/ConfigChangeRequest.php</code>
- Create: <code>app/Operations/Handlers/ConfigApplyHandler.php</code>
- Create: <code>app/Operations/Handlers/ConfigRestoreHandler.php</code>
- Create: <code>app/Models/ConfigFile.php</code>
- Create: <code>app/Models/ConfigRevision.php</code>
- Create: <code>database/migrations/*_create_config_tables.php</code>
- Create: <code>tests/Feature/Config/ConfigChangeServiceTest.php</code>
- Create: <code>tests/Feature/Config/ConfigConflictTest.php</code>

**Interfaces:**
- Consumes: format registry, filesystem, snapshots, and operations.
- Produces: <code>ConfigChangeService::propose(ConfigChangeRequest, OperationAuthor): Operation</code> and <code>ConfigRevisionService::restore(ConfigRevision, User): Operation</code>.

- [ ] **Step 1: Write stale-edit, validation, and rollback tests**

~~~php
it('returns a conflict instead of overwriting a file changed outside CraftKeeper', function () {
    $request = new ConfigChangeRequest('server.properties', 'old-sha', [
        ConfigChange::replace('allow-flight', true),
    ]);

    expect(fn () => app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1)))
        ->toThrow(ConfigConflict::class);
});
~~~

- [ ] **Step 2: Implement proposal generation**

The proposal stores base hash, redacted before/after values, unified diff, validation result, restart requirement, risk, documentation citations, and expiration. Secret values appear as <code>••••••</code> in diffs and audit metadata.

- [ ] **Step 3: Implement apply and restore**

Approval enqueues one operation. The handler repeats hash and validation checks immediately before writing. Restore is a new proposal against current content, not a blind file copy. A failed post-write verification attempts the captured snapshot and records both failures if rollback also fails.

- [ ] **Step 4: Verify config transactions**

Run:

~~~bash
php artisan test tests/Feature/Config
~~~

Expected: validation prevents approval, stale edits return HTTP 409, approved edits create one revision and audit event, and restore remains reviewable.

- [ ] **Step 5: Commit**

~~~bash
git add app/Config/ConfigChangeService.php app/Config/ConfigRevisionService.php app/Operations/Handlers/ConfigApplyHandler.php app/Operations/Handlers/ConfigRestoreHandler.php app/Models/ConfigFile.php app/Models/ConfigRevision.php database/migrations tests/Feature/Config
git commit -m "feat: add reversible configuration operations"
~~~

### Task 9: Configuration Inventory and Editor Experience

**Files:**
- Create: <code>app/Http/Controllers/ConfigController.php</code>
- Create: <code>resources/js/pages/config/Index.tsx</code>
- Create: <code>resources/js/pages/config/Edit.tsx</code>
- Create: <code>resources/js/pages/config/Conflict.tsx</code>
- Create: <code>resources/js/pages/config/History.tsx</code>
- Create: <code>resources/js/features/config/GuidedEditor.tsx</code>
- Create: <code>resources/js/features/config/StructuredEditor.tsx</code>
- Create: <code>resources/js/features/config/SourceEditor.tsx</code>
- Create: <code>resources/js/features/config/DiffReview.tsx</code>
- Create: <code>resources/js/features/config/ConfigPreview.tsx</code>
- Create: <code>tests/Feature/Http/ConfigControllerTest.php</code>
- Create: <code>resources/js/features/config/DiffReview.test.tsx</code>
- Create: <code>tests/Browser/ConfigEditorTest.php</code>
- Modify: <code>routes/web.php</code>

**Interfaces:**
- Consumes: configuration services from Tasks 6–8.
- Produces: all configuration inventory, preview, edit, review, conflict, history, and restore routes.

- [ ] **Step 1: Write controller authorization and response tests**

~~~php
it('never sends raw secret values to the browser', function () {
    $this->actingAs(User::factory()->create())
        ->get('/configurations/plugins/Geyser-Spigot/config.yml')
        ->assertOk()
        ->assertDontSee('actual-secret-value');
});
~~~

- [ ] **Step 2: Implement inventory and preview**

Group files by Server, Paper, Geyser/Floodgate, and plugin. Include filename, relative path, format, recognized/generic state, provenance, modified time, validation state, restart requirement, and a bounded source preview. Search operates on names, paths, schema labels, and plugin names, never secret values.

- [ ] **Step 3: Implement three edit modes and review**

Guided mode uses schema fields and inline official documentation. Structured mode renders nested objects/arrays. Source mode uses a monospaced editor with diagnostics. All modes converge on the same <code>ConfigChangeRequest</code>, then show unified diff, consequences, validation, restart effect, risk, and approve/cancel.

- [ ] **Step 4: Implement responsive and conflict states**

At desktop, editor and assistant/context can use split panes. Below 768px use full-screen editor and bottom-sheet review. Conflict view shows base, disk, and proposed values with actions: reload disk, copy proposed value, or create a fresh proposal from manually selected values.

- [ ] **Step 5: Verify against mockups**

Run:

~~~bash
php artisan test tests/Feature/Http/ConfigControllerTest.php
npm run test -- DiffReview
npm run e2e -- --grep "configuration"
~~~

Expected: all editor modes create identical domain changes, secrets remain redacted, keyboard-only save/review works, and mobile has no hidden approval controls.

- [ ] **Step 6: Commit**

~~~bash
git add app/Http/Controllers/ConfigController.php resources/js/pages/config resources/js/features/config routes/web.php tests/Feature/Http/ConfigControllerTest.php tests/Browser/ConfigEditorTest.php
git commit -m "feat: build first-class configuration editor"
~~~

### Task 10: RCON Protocol, Command Policy, and Server Actions

**Files:**
- Create: <code>app/Console/RconTransport.php</code>
- Create: <code>app/Console/StreamRconTransport.php</code>
- Create: <code>app/Console/MinecraftRconClient.php</code>
- Create: <code>app/Console/CommandPolicy.php</code>
- Create: <code>app/Console/RconCommand.php</code>
- Create: <code>app/Operations/Handlers/RconCommandHandler.php</code>
- Create: <code>app/Operations/Handlers/ServerStopHandler.php</code>
- Create: <code>tests/Unit/Console/MinecraftRconClientTest.php</code>
- Create: <code>tests/Feature/Console/CommandPolicyTest.php</code>
- Create: <code>tests/fixtures/rcon/FakeRconTransport.php</code>

**Interfaces:**
- Consumes: encrypted RCON settings and operation lifecycle.
- Produces: <code>RconClient::execute(RconCommand): RconResponse</code>, <code>CommandPolicy::classify(string): CommandRisk</code>, and audited RCON operations.

- [ ] **Step 1: Write protocol and policy tests before opening a socket**

~~~php
it('rejects malformed and oversized RCON packets', function () {
    $transport = FakeRconTransport::respondingWith(pack('V', 99_999_999));
    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

it('classifies stop, op, deop, ban, whitelist, gamerule, and raw execute as elevated', function (string $command) {
    expect(app(CommandPolicy::class)->classify($command))->toBe(CommandRisk::Elevated);
})->with(['stop', 'op Steve', 'ban Steve', 'whitelist on', 'gamerule keepInventory true', 'execute as @a run kill @s']);
~~~

- [ ] **Step 2: Implement a bounded Minecraft Source RCON client**

Use <code>stream_socket_client</code> with separate 3-second connect and 5-second read timeouts. Encode/decode little-endian request ID, packet type, body, and two NUL terminators. Limit commands to 4 KiB and accumulated responses to 1 MiB. Treat authentication ID <code>-1</code>, timeout, EOF, invalid length, and request-ID mismatch as typed failures.

- [ ] **Step 3: Implement command rules**

Safe predefined actions are <code>list</code>, <code>save-all flush</code>, <code>say</code>, <code>time query daytime</code>, and <code>weather query</code>. Elevated actions require the consequence panel and a fresh approval. Secret-like console strings are not persisted; store command category and a redacted display value when the input matches configured secret patterns.

- [ ] **Step 4: Implement graceful stop**

The stop action first runs <code>save-all flush</code>, then <code>stop</code>. CraftKeeper reports “Waiting for the Minecraft container restart policy” and polls RCON until it becomes unavailable and then healthy. It never calls Docker.

- [ ] **Step 5: Verify RCON behavior**

Run:

~~~bash
php artisan test tests/Unit/Console tests/Feature/Console
~~~

Expected: packet fragmentation, multi-packet response, bad auth, timeout, oversized response, policy, and graceful-stop sequence tests pass without network access.

- [ ] **Step 6: Commit**

~~~bash
git add app/Console app/Operations/Handlers/RconCommandHandler.php app/Operations/Handlers/ServerStopHandler.php tests/Unit/Console tests/Feature/Console tests/fixtures/rcon
git commit -m "feat: add safe audited RCON control"
~~~

### Task 11: Server Status, Players, Logs, and Realtime Console

**Files:**
- Create: <code>app/Server/ServerStatusService.php</code>
- Create: <code>app/Server/PlayerService.php</code>
- Create: <code>app/Server/LogTailService.php</code>
- Create: <code>app/Server/LogParser.php</code>
- Create: <code>app/Models/ServerSample.php</code>
- Create: <code>app/Models/Player.php</code>
- Create: <code>app/Models/PlayerEvent.php</code>
- Create: <code>app/Models/ConsoleEntry.php</code>
- Create: <code>app/Events/ConsoleEntryReceived.php</code>
- Create: <code>app/Console/Commands/SampleServerState.php</code>
- Create: <code>database/migrations/*_create_server_observation_tables.php</code>
- Create: <code>tests/Unit/Server/LogParserTest.php</code>
- Create: <code>tests/Feature/Server/ServerStatusServiceTest.php</code>

**Interfaces:**
- Consumes: RCON client, Minecraft filesystem, scheduler, and Reverb.
- Produces: current server health, bounded history, player events, parsed log entries, and private <code>server.console</code> updates.

- [ ] **Step 1: Write representative parser and outage tests**

~~~php
it('parses Floodgate Bedrock join and floating kick without dropping the raw line', function () {
    $events = app(LogParser::class)->parse([
        '[12:24:20 INFO]: [floodgate] Floodgate player logged in as .aacarm',
        '[12:24:27 WARN]: .aacarm was kicked for floating too long!',
    ]);

    expect($events[0]->player)->toBe('.aacarm')
        ->and($events[0]->platform)->toBe(PlayerPlatform::Bedrock)
        ->and($events[1]->kind)->toBe(LogEventKind::Kick);
});
~~~

- [ ] **Step 2: Implement bounded observation**

Poll lightweight RCON state every 15 seconds while reachable. Store 7 days of one-minute samples and 30 days of player/audit events; prune with a daily scheduled command. Do not build metrics aggregation, Prometheus, tracing, or long-term log storage in V1.

- [ ] **Step 3: Tail logs safely**

Track file inode and byte offset, handle rotation/truncation, read at most 256 KiB per iteration, sanitize ANSI/control sequences, and cap UI payload lines at 16 KiB. Persist parsed event summaries and a bounded recent console buffer; keep arbitrary historical log search on disk with range limits.

- [ ] **Step 4: Verify degraded behavior**

Run:

~~~bash
php artisan test tests/Unit/Server tests/Feature/Server
~~~

Expected: an unavailable RCON endpoint marks only RCON-dependent cards degraded, file-based logs remain usable, and retry backoff has jitter and a 60-second ceiling.

- [ ] **Step 5: Commit**

~~~bash
git add app/Server app/Models/ServerSample.php app/Models/Player.php app/Models/PlayerEvent.php app/Models/ConsoleEntry.php app/Events/ConsoleEntryReceived.php app/Console/Commands/SampleServerState.php database/migrations tests/Unit/Server tests/Feature/Server
git commit -m "feat: observe server players and logs"
~~~

### Task 12: Overview, Server, Players, Console, Logs, and Activity UI

**Files:**
- Create: <code>app/Http/Controllers/OverviewController.php</code>
- Create: <code>app/Http/Controllers/ServerController.php</code>
- Create: <code>app/Http/Controllers/ConsoleController.php</code>
- Create: <code>app/Http/Controllers/LogController.php</code>
- Create: <code>app/Http/Controllers/ActivityController.php</code>
- Create: <code>resources/js/pages/Overview.tsx</code>
- Create: <code>resources/js/pages/server/Index.tsx</code>
- Create: <code>resources/js/pages/server/Players.tsx</code>
- Create: <code>resources/js/pages/server/Console.tsx</code>
- Create: <code>resources/js/pages/server/Logs.tsx</code>
- Create: <code>resources/js/pages/Activity.tsx</code>
- Create: <code>resources/js/features/console/CommandComposer.tsx</code>
- Create: <code>resources/js/features/operations/OperationProgress.tsx</code>
- Create: <code>tests/Browser/ServerOperationsTest.php</code>
- Modify: <code>routes/web.php</code>

**Interfaces:**
- Consumes: status, players, logs, console, audit, and Reverb data.
- Produces: mocked operational pages plus the player and activity detail variants.

- [ ] **Step 1: Write browser tests for safe and elevated commands**

~~~php
it('requires consequence review before sending an elevated command', function () {
    $page = visit('/server/console');
    $page->type('[data-testid=command-input]', 'stop')
        ->press('Compose command')
        ->assertSee('Stops the Minecraft server')
        ->assertSee('Approval required')
        ->assertNoJavascriptErrors();
});
~~~

- [ ] **Step 2: Build Overview and Server**

Overview uses server health, online players, resource summary when available, pending restart, recent operations, recent player activity, and attention items. Server detail shows connection/RCON status, version data discovered from logs/JAR metadata, paths, and predefined safe actions. Unknown data displays “Unavailable” with reason, never a fabricated zero.

- [ ] **Step 3: Build Console, Logs, and Players**

Console follows the design mockup with reconnect state, pause/follow, filters, copy, clear-view, history, predefined actions, and approval panels. Logs provide source, level, time, player, and text filters, context lines, and copy/download of bounded results. Player actions use exact Java/Floodgate identity and never infer an offline UUID from a display name.

- [ ] **Step 4: Build Activity**

Display a chronological, filterable union of operations, config changes, plugin changes, commands, server restarts, player events, AI proposals, API calls, and MCP calls. Each item shows actor, source, timestamp, status, redacted summary, and correlation link.

- [ ] **Step 5: Verify responsive operational workflows**

Run:

~~~bash
npm run e2e -- --grep "overview|server|console|logs|players|activity"
~~~

Expected: mocked layouts match their desktop hierarchy; the mobile console and approval bottom sheet remain usable at 390×844; websocket loss shows reconnect without losing composed input.

- [ ] **Step 6: Commit**

~~~bash
git add app/Http/Controllers/OverviewController.php app/Http/Controllers/ServerController.php app/Http/Controllers/ConsoleController.php app/Http/Controllers/LogController.php app/Http/Controllers/ActivityController.php resources/js/pages/Overview.tsx resources/js/pages/server resources/js/pages/Activity.tsx resources/js/features/console resources/js/features/operations routes/web.php tests/Browser/ServerOperationsTest.php
git commit -m "feat: build server operations workspace"
~~~

### Task 13: Plugin Inspection, Inventory, and Compatibility

**Files:**
- Create: <code>app/Plugins/JarInspector.php</code>
- Create: <code>app/Plugins/PluginInventoryService.php</code>
- Create: <code>app/Plugins/PluginCompatibilityService.php</code>
- Create: <code>app/Models/PluginInstallation.php</code>
- Create: <code>app/Models/PluginArtifact.php</code>
- Create: <code>database/migrations/*_create_plugin_tables.php</code>
- Create: <code>tests/Unit/Plugins/JarInspectorTest.php</code>
- Create: <code>tests/Feature/Plugins/PluginInventoryServiceTest.php</code>
- Create: <code>tests/fixtures/plugins/</code>

**Interfaces:**
- Consumes: contained filesystem and <code>ZipArchive</code>.
- Produces: <code>JarInspector::inspect(MinecraftPath): InspectedPlugin</code>, inventory reconciliation, dependency graph, provenance, and compatibility evidence.

- [ ] **Step 1: Write hostile and valid JAR tests**

~~~php
it('reads plugin metadata without extracting archive entries', function () {
    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/example.jar'));
    expect($plugin->name)->toBe('Example')
        ->and($plugin->sha256)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plugin->metadataSource)->toBe('paper-plugin.yml');
});
~~~

Test ZIP bombs, missing metadata, malformed YAML, duplicate plugin names, <code>../</code> entries, huge metadata, <code>plugin.yml</code> fallback, hard/soft dependencies, API version, and checksums.

- [ ] **Step 2: Implement inspection without extraction**

Read only <code>paper-plugin.yml</code> or <code>plugin.yml</code> from the archive; cap metadata at 256 KiB and entry count at 10,000. Never load or execute plugin classes. Store SHA-256, size, modified time, version, main class, API version, dependencies, and source.

- [ ] **Step 3: Implement evidence-based compatibility**

Compatibility states are Compatible, Incompatible, Unknown, and Warning. Evidence may come from catalog release metadata, source platform declarations, dependency satisfaction, and API-version comparison. Unknown must remain unknown; do not infer support merely because a JAR loads.

- [ ] **Step 4: Reconcile disk and database**

Detect additions, removals, changes, duplicate names, disabled <code>.jar.disabled</code> files, update staging, and rollback artifacts. Preserve manual provenance unless a checksum exactly matches a source release.

- [ ] **Step 5: Verify inventory**

Run:

~~~bash
php artisan test tests/Unit/Plugins tests/Feature/Plugins
~~~

Expected: hostile archives fail with typed diagnostics; inventory is deterministic and never extracts or executes uploaded code.

- [ ] **Step 6: Commit**

~~~bash
git add app/Plugins/JarInspector.php app/Plugins/PluginInventoryService.php app/Plugins/PluginCompatibilityService.php app/Models/PluginInstallation.php app/Models/PluginArtifact.php database/migrations tests/Unit/Plugins tests/Feature/Plugins tests/fixtures/plugins
git commit -m "feat: inspect and inventory Minecraft plugins"
~~~

### Task 14: Unified Plugin Catalog, Hangar, and Modrinth

**Files:**
- Create: <code>resources/catalog/plugin-catalog.schema.json</code>
- Create: <code>app/Catalog/PluginCatalogClient.php</code>
- Create: <code>app/Catalog/Sources/CraftKeeperCatalogSource.php</code>
- Create: <code>app/Catalog/Sources/HangarSource.php</code>
- Create: <code>app/Catalog/Sources/ModrinthSource.php</code>
- Create: <code>app/Catalog/UnifiedCatalogService.php</code>
- Create: <code>app/Catalog/Data/PluginRelease.php</code>
- Create: <code>app/Catalog/Data/PluginSearchQuery.php</code>
- Create: <code>app/Catalog/Data/PluginSearchPage.php</code>
- Create: <code>app/Models/CatalogCacheEntry.php</code>
- Create: <code>app/Models/CatalogSourceState.php</code>
- Create: <code>database/migrations/*_create_catalog_tables.php</code>
- Create: <code>tests/Contract/Catalog/PluginCatalogContractTest.php</code>
- Create: <code>tests/Feature/Catalog/UnifiedCatalogServiceTest.php</code>
- Create: <code>tests/fixtures/catalog/</code>
- Create: <code>docs/architecture/plugin-catalog.md</code>

**Interfaces:**
- Consumes: public JSON from <code>carmelosantana/minecraft-plugin-catalog</code>, Hangar API, and Modrinth API.
- Produces: <code>PluginSource</code> adapters and <code>UnifiedCatalogService::search(PluginSearchQuery): PluginSearchPage</code>.

- [ ] **Step 1: Lock the shared catalog contract**

The JSON Schema requires catalog version, plugin slug/name/description, project URL, license, source repository, releases, Minecraft versions, platforms, dependencies, download URL, SHA-256, release timestamp, and signature metadata when present. Add fixtures for valid, invalid-hash, missing-version, and withdrawn releases.

- [ ] **Step 2: Implement source clients with transport fixtures**

Use Laravel HTTP client with 5-second connect, 15-second request timeout, two retries only for idempotent transient errors, ETag/Last-Modified caching, explicit user agent, and response-size limits. Cache successful normalized pages for 15 minutes and retain the last successful CraftKeeper catalog for seven days.

- [ ] **Step 3: Merge without erasing provenance**

Deduplicate exact project/source identities, not names. Every result retains its source badge and source URL. Sort by compatibility confidence, installed relevance, source trust, and source ranking; do not create an opaque popularity score.

- [ ] **Step 4: Define the independent catalog repository handoff**

<code>docs/architecture/plugin-catalog.md</code> must specify that the existing plugin updater and CraftKeeper consume the same versioned JSON contract, releases are immutable by checksum, schema validation runs in that repository’s CI, and CraftKeeper remains functional when the catalog is unavailable.

- [ ] **Step 5: Verify source isolation**

Run:

~~~bash
php artisan test tests/Contract/Catalog tests/Feature/Catalog
~~~

Expected: one failed source produces a labeled degraded result while successful sources and cached results remain available.

- [ ] **Step 6: Commit**

~~~bash
git add resources/catalog app/Catalog app/Models/CatalogCacheEntry.php app/Models/CatalogSourceState.php database/migrations tests/Contract/Catalog tests/Feature/Catalog tests/fixtures/catalog docs/architecture/plugin-catalog.md
git commit -m "feat: add unified plugin catalog"
~~~

### Task 15: Safe Plugin Lifecycle and Plugin Management UI

**Files:**
- Create: <code>app/Plugins/PluginLifecycleService.php</code>
- Create: <code>app/Plugins/PluginDownloader.php</code>
- Create: <code>app/Plugins/PluginUploadService.php</code>
- Create: <code>app/Operations/Handlers/PluginOperationHandler.php</code>
- Create: <code>app/Http/Controllers/PluginController.php</code>
- Create: <code>resources/js/pages/plugins/Index.tsx</code>
- Create: <code>resources/js/pages/plugins/Discover.tsx</code>
- Create: <code>resources/js/pages/plugins/Show.tsx</code>
- Create: <code>resources/js/pages/plugins/Upload.tsx</code>
- Create: <code>resources/js/pages/plugins/Operation.tsx</code>
- Create: <code>resources/js/features/plugins/CompatibilityEvidence.tsx</code>
- Create: <code>tests/Feature/Plugins/PluginLifecycleServiceTest.php</code>
- Create: <code>tests/Browser/PluginManagementTest.php</code>
- Modify: <code>routes/web.php</code>

**Interfaces:**
- Consumes: inventory, catalog, operations, filesystem, and JAR inspection.
- Produces: proposed install/update/disable/remove/rollback operations and all plugin pages.

- [ ] **Step 1: Write download, upload, rollback, and dependency tests**

~~~php
it('rejects a downloaded artifact whose checksum differs from release metadata', function () {
    Http::fake(['*' => Http::response('not-the-published-jar')]);
    $release = PluginRelease::fromArray([
        'id' => 'catalog:example:1.0.0',
        'name' => 'Example',
        'version' => '1.0.0',
        'download_url' => 'https://catalog.example/plugins/example-1.0.0.jar',
        'sha256' => str_repeat('a', 64),
        'minecraft_versions' => ['1.21.8'],
        'platforms' => ['paper'],
    ]);
    expect(fn () => app(PluginDownloader::class)->download($release))
        ->toThrow(PluginChecksumMismatch::class);
});
~~~

- [ ] **Step 2: Implement quarantine and planning**

Stream downloads/uploads to <code>/data/quarantine/{operation-id}</code>, cap at a configurable 100 MiB, calculate SHA-256 during streaming, inspect metadata, validate expected identity, and produce an install plan listing artifact, source, checksum, compatibility evidence, dependencies, conflicts, file changes, rollback artifact, and restart requirement.

- [ ] **Step 3: Implement lifecycle operations**

Installation and update stage a same-filesystem file then atomically rename into <code>/minecraft/plugins</code>. Preserve the replaced JAR under <code>/data/plugin-rollbacks</code>. Disable renames to <code>.jar.disabled</code>; remove moves to rollback storage, never unlinks immediately. Keep three artifacts per plugin for 30 days. Every lifecycle operation is restart-required and remains visible until a subsequent server start is observed.

- [ ] **Step 4: Build plugin pages**

Follow Plugin Discovery mockup for search/filter/results. Installed list and detail show disk state, source, current/latest version, compatibility evidence, dependencies, config links, checksum, update availability, pending action, history, and guarded actions. Manual upload displays inspection findings before an install proposal.

- [ ] **Step 5: Verify plugin workflows**

Run:

~~~bash
php artisan test tests/Feature/Plugins
npm run e2e -- --grep "plugin"
~~~

Expected: mismatched downloads never reach <code>/minecraft</code>; update failure leaves the installed artifact intact; restart-required and rollback controls are visible on desktop and mobile.

- [ ] **Step 6: Commit**

~~~bash
git add app/Plugins/PluginLifecycleService.php app/Plugins/PluginDownloader.php app/Plugins/PluginUploadService.php app/Operations/Handlers/PluginOperationHandler.php app/Http/Controllers/PluginController.php resources/js/pages/plugins resources/js/features/plugins routes/web.php tests/Feature/Plugins tests/Browser/PluginManagementTest.php
git commit -m "feat: add reversible plugin management"
~~~

### Task 16: Optional AI Providers, Redaction, Documentation Context, and Assistant

**Files:**
- Create: <code>app/Ai/AiManager.php</code>
- Create: <code>app/Ai/Providers/OpenAiCompatibleProvider.php</code>
- Create: <code>app/Ai/Providers/OllamaProvider.php</code>
- Create: <code>app/Ai/SecretRedactor.php</code>
- Create: <code>app/Ai/ContextBuilder.php</code>
- Create: <code>app/Ai/DocumentationIndex.php</code>
- Create: <code>app/Ai/Tools/ReadConfigTool.php</code>
- Create: <code>app/Ai/Tools/ProposeConfigChangeTool.php</code>
- Create: <code>app/Ai/Tools/ComposeRconCommandTool.php</code>
- Create: <code>app/Models/AiProviderConfiguration.php</code>
- Create: <code>app/Models/AiConversation.php</code>
- Create: <code>app/Models/AiMessage.php</code>
- Create: <code>app/Http/Controllers/AssistantController.php</code>
- Create: <code>resources/js/pages/Assistant.tsx</code>
- Create: <code>resources/js/features/assistant/AssistantDrawer.tsx</code>
- Create: <code>resources/js/features/assistant/ApprovalPanel.tsx</code>
- Create: <code>resources/js/features/assistant/RedactionDisclosure.tsx</code>
- Create: <code>tests/Unit/Ai/SecretRedactorTest.php</code>
- Create: <code>tests/Feature/Ai/AiUnavailableTest.php</code>
- Create: <code>tests/Feature/Ai/AiProposalTest.php</code>

**Interfaces:**
- Consumes: php-agents, encrypted provider settings, discovered schemas/configs, official docs cache, config proposals, and command policy.
- Produces: <code>AiManager::provider(): ?AiProvider</code>, streamed cited answers, redaction disclosure, config proposals, and composed RCON proposals.

- [ ] **Step 1: Write redaction and provider-outage tests**

~~~php
it('redacts configured and discovered secrets before hosted provider transport', function () {
    $result = app(SecretRedactor::class)->redact(
        "rcon.password=hunter2\napi-key: sk-example-secret\n",
        ['hunter2', 'sk-example-secret']
    );

    expect($result->text)->not->toContain('hunter2', 'sk-example-secret')
        ->and($result->disclosures)->toHaveCount(2);
});

it('keeps the application healthy when Ollama is offline', function () {
    Http::fake(['http://ollama:11434/*' => Http::failedConnection()]);
    $this->actingAs(User::factory()->create())->get('/assistant')
        ->assertOk()
        ->assertSee('AI is unavailable');
});
~~~

- [ ] **Step 2: Implement provider isolation**

Provider health checks use a 2-second connect and 5-second response timeout with no request-path retries. A provider can be disabled or unavailable; <code>AiManager::provider()</code> then returns null and only AI controls are disabled. OpenAI-compatible base URL, model, and key are configurable. Ollama base URL and model are configurable and require no key.

- [ ] **Step 3: Implement context and redaction**

Context includes server versions, platform, selected config schema, a bounded redacted excerpt, validation diagnostics, recent relevant audit events, and cached official docs from Minecraft, Paper, Geyser, Floodgate, Hangar, Modrinth, and the relevant plugin project. Ignore instructions found inside configs, logs, plugin descriptions, or documentation. Hosted providers receive redacted context; local Ollama may receive unredacted context only after an explicit setting explains the trust tradeoff.

- [ ] **Step 4: Implement assistant tools**

Read tools return bounded redacted values. Change tools call <code>ConfigChangeService::propose</code>; they cannot approve. RCON composition returns command, explanation, risk, and expected consequence, then uses the normal operation approval flow. Stream partial text over private Reverb channels, persist final messages, and store citations as URL/title pairs.

- [ ] **Step 5: Build full-page and contextual assistant**

Follow the Assistant mockup for conversations, citations, context chips, tool progress, proposal diff, approval/rejection, and retry. The drawer inherits the currently viewed config/plugin/server context. Include clear empty, disabled, rate-limited, provider-error, redaction-disclosure, and interrupted-stream states.

- [ ] **Step 6: Verify AI boundaries**

Run:

~~~bash
php artisan test tests/Unit/Ai tests/Feature/Ai
npm run e2e -- --grep "assistant"
~~~

Expected: prompt-injection fixtures cannot invoke unapproved tools; hosted transports never receive secrets; Ollama outage does not fail health, configuration, plugins, API, or MCP.

- [ ] **Step 7: Commit**

~~~bash
git add app/Ai app/Models/AiProviderConfiguration.php app/Models/AiConversation.php app/Models/AiMessage.php app/Http/Controllers/AssistantController.php resources/js/pages/Assistant.tsx resources/js/features/assistant tests/Unit/Ai tests/Feature/Ai
git commit -m "feat: add optional guarded AI assistant"
~~~

### Task 17: Versioned REST API, Scoped Tokens, and OpenAPI

**Files:**
- Create: <code>app/Http/Controllers/Api/V1/ServerController.php</code>
- Create: <code>app/Http/Controllers/Api/V1/ConfigController.php</code>
- Create: <code>app/Http/Controllers/Api/V1/PluginController.php</code>
- Create: <code>app/Http/Controllers/Api/V1/OperationController.php</code>
- Create: <code>app/Http/Resources/Api/V1/</code>
- Create: <code>app/Support/ApiScope.php</code>
- Create: <code>app/Policies/ApiOperationPolicy.php</code>
- Create: <code>openapi.yaml</code>
- Create: <code>resources/js/pages/integrations/Api.tsx</code>
- Create: <code>tests/Feature/Api/V1/ApiScopeTest.php</code>
- Create: <code>tests/Contract/Api/OpenApiTest.php</code>
- Modify: <code>routes/api.php</code>

**Interfaces:**
- Consumes: Sanctum personal access tokens and application services.
- Produces: <code>/api/v1</code>, scoped tokens, cursor pagination, idempotent proposal creation, and a checked OpenAPI 3.1 contract.

- [ ] **Step 1: Write scope and serialization tests**

~~~php
it('does not let a read token propose or approve a config change', function () {
    $token = User::factory()->create()->createToken('reader', ['server:read', 'config:read']);
    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/config/proposals', [])
        ->assertForbidden();
});
~~~

- [ ] **Step 2: Define exact scopes**

~~~php
enum ApiScope: string
{
    case ServerRead = 'server:read';
    case ConfigRead = 'config:read';
    case ConfigPropose = 'config:propose';
    case ConfigApply = 'config:apply';
    case PluginsRead = 'plugins:read';
    case PluginsManage = 'plugins:manage';
    case ActivityRead = 'activity:read';
    case RconSafe = 'rcon:safe';
    case RconAdmin = 'rcon:admin';
}
~~~

<code>config:apply</code> may execute only a proposal already approved by the human admin. <code>rcon:admin</code> may create elevated proposals but cannot approve them. Token values are shown exactly once and stored hashed by Sanctum.

- [ ] **Step 3: Implement stable API behavior**

Use JSON error objects with <code>code</code>, <code>message</code>, <code>details</code>, and <code>correlation_id</code>. Use cursor pagination for collections, RFC 3339 UTC timestamps, ETags for config reads, HTTP 409 for stale hashes, 422 for validation, 429 for throttling, and <code>Idempotency-Key</code> for mutation proposals.

- [ ] **Step 4: Write and validate OpenAPI**

Document auth, scopes, all endpoints, request/response schemas, pagination, errors, proposal/approval separation, examples with redacted values, and webhook absence in V1. Ensure contract tests compare registered routes and operation IDs with <code>openapi.yaml</code>.

- [ ] **Step 5: Verify the API**

Run:

~~~bash
php artisan test tests/Feature/Api tests/Contract/Api
~~~

Expected: every endpoint rejects missing scope, secrets never serialize, repeated idempotency keys return the original proposal, and OpenAPI covers every <code>/api/v1</code> route.

- [ ] **Step 6: Commit**

~~~bash
git add app/Http/Controllers/Api app/Http/Resources/Api app/Support/ApiScope.php app/Policies/ApiOperationPolicy.php openapi.yaml resources/js/pages/integrations/Api.tsx routes/api.php tests/Feature/Api tests/Contract/Api
git commit -m "feat: publish scoped REST API v1"
~~~

### Task 18: MCP Server, OAuth Grants, Resources, and Guarded Tools

**Files:**
- Create: <code>app/Mcp/Servers/CraftKeeperServer.php</code>
- Create: <code>app/Mcp/Resources/ServerStatusResource.php</code>
- Create: <code>app/Mcp/Resources/ConfigResource.php</code>
- Create: <code>app/Mcp/Resources/PluginResource.php</code>
- Create: <code>app/Mcp/Tools/ProposeConfigChange.php</code>
- Create: <code>app/Mcp/Tools/ProposePluginOperation.php</code>
- Create: <code>app/Mcp/Tools/RunSafeRcon.php</code>
- Create: <code>app/Mcp/Prompts/DiagnoseServer.php</code>
- Create: <code>app/Models/McpGrant.php</code>
- Create: <code>app/Policies/McpGrantPolicy.php</code>
- Create: <code>database/migrations/*_create_mcp_grants_table.php</code>
- Create: <code>resources/js/pages/integrations/Mcp.tsx</code>
- Create: <code>tests/Feature/Mcp/McpAuthorizationTest.php</code>
- Create: <code>tests/Contract/Mcp/McpCapabilityTest.php</code>
- Create: <code>tests/Concerns/CallsMcp.php</code>
- Modify: <code>routes/mcp.php</code>

**Interfaces:**
- Consumes: Laravel MCP, Passport OAuth 2.1, API scopes, read services, and operation proposals.
- Produces: web MCP endpoint <code>/mcp/craftkeeper</code>, OAuth consent/grants, bounded resources, prompts, and policy-checked tools.

- [ ] **Step 1: Write grant and self-approval tests**

~~~php
it('lets a config-propose grant create but not approve a proposal', function () {
    $grant = McpGrant::factory()->withScopes(['config:read', 'config:propose'])->create();
    $proposal = $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => str_repeat('a', 64),
        'changes' => [['path' => 'allow-flight', 'value' => true]],
    ]);
    expect($proposal['status'])->toBe('proposed');
    $this->callMcpTool($grant, 'approve_operation', ['id' => $proposal['id']])
        ->assertMcpToolNotFound();
});
~~~

- [ ] **Step 2: Configure OAuth and internal grants**

Use Passport authorization-code flow with PKCE and explicit admin consent. An OAuth client maps to one <code>McpGrant</code> containing the same scope strings as Task 17, expiry, revocation time, last-used time, and display name. No password grant, client-credentials grant, dynamic registration, or anonymous MCP access in V1.

- [ ] **Step 3: Implement resources and tools**

Resources expose bounded, redacted server status, config inventory/content, plugin inventory, and recent activity. Tools are limited to config proposal, plugin-operation proposal, and safe RCON. There is no approval tool, raw filesystem tool, arbitrary source editor, secret reader, shell tool, Docker tool, or elevated RCON tool.

- [ ] **Step 4: Add capability and audit UI**

The MCP integration page follows the Integrations design: connection URL, authorization state, exact capabilities/scopes, last used, expiry, revoke, and recent calls. Consent copy names each consequence. Every MCP request records client, tool/resource, scope decision, correlation ID, redacted arguments, duration, and outcome.

- [ ] **Step 5: Verify with a real MCP client**

Run:

~~~bash
php artisan test tests/Feature/Mcp tests/Contract/Mcp
php artisan mcp:inspector mcp/craftkeeper
~~~

Expected: inspector lists only documented capabilities; revoked/expired grants fail; resource bounds and redaction hold; proposals appear in the UI approval queue.

- [ ] **Step 6: Commit**

~~~bash
git add app/Mcp app/Models/McpGrant.php app/Policies/McpGrantPolicy.php database/migrations resources/js/pages/integrations/Mcp.tsx routes/mcp.php tests/Feature/Mcp tests/Contract/Mcp
git commit -m "feat: expose guarded OAuth MCP server"
~~~

### Task 19: Integrations, Settings, Backups, Diagnostics, and Optional Analytics

**Files:**
- Create: <code>app/Support/BackupService.php</code>
- Create: <code>app/Support/SupportBundleService.php</code>
- Create: <code>app/Support/UmamiScript.php</code>
- Create: <code>app/Http/Controllers/IntegrationController.php</code>
- Create: <code>app/Http/Controllers/SettingsController.php</code>
- Create: <code>app/Http/Controllers/BackupController.php</code>
- Create: <code>resources/js/pages/Integrations.tsx</code>
- Create: <code>resources/js/pages/Settings.tsx</code>
- Create: <code>resources/js/pages/settings/Server.tsx</code>
- Create: <code>resources/js/pages/settings/Ai.tsx</code>
- Create: <code>resources/js/pages/settings/Appearance.tsx</code>
- Create: <code>resources/js/pages/settings/Analytics.tsx</code>
- Create: <code>resources/js/pages/settings/Backups.tsx</code>
- Create: <code>resources/js/pages/settings/Advanced.tsx</code>
- Create: <code>tests/Feature/Settings/OptionalIntegrationsTest.php</code>
- Create: <code>tests/Feature/Support/SupportBundleTest.php</code>

**Interfaces:**
- Consumes: settings/secrets, all integration health checks, snapshots, audit, and design tokens.
- Produces: complete integration/settings pages, SQLite/app-state backup, redacted diagnostics archive, and optional Umami tag.

- [ ] **Step 1: Write optional-integration and redaction tests**

~~~php
it('renders no analytics request when Umami is disabled', function () {
    Setting::put('analytics.umami.enabled', false);
    $this->actingAs(User::factory()->create())->get('/overview')
        ->assertOk()
        ->assertDontSee('umami', false);
});
~~~

- [ ] **Step 2: Implement optional Umami**

Render the script only when enabled and both a validated HTTPS script URL and website ID exist. Add <code>defer</code>, never proxy it through the backend, and treat load failure as invisible to application health. The CSP permits only the configured origin when enabled. No analytics dependency appears in Composer or npm.

- [ ] **Step 3: Implement backups and support bundles**

Use SQLite online backup semantics, then archive database, non-secret settings, catalog cache metadata, and CraftKeeper configuration under <code>/data/backups</code>. Do not back up Minecraft worlds in V1. Support bundles include versions, health, permissions, redacted settings, sanitized recent logs, operation failures, and checksums; exclude secrets, tokens, chat content, config secret values, and full uploaded JARs.

- [ ] **Step 4: Complete settings and integration health**

Settings sections are General/Server, Security, AI Providers, Appearance, Analytics, Backups, API, MCP, and Advanced. Integrations show Minecraft directory, RCON, AI, CraftKeeper Catalog, Hangar, Modrinth, official documentation cache, API, MCP, and Umami with Connected, Disabled, Degraded, or Misconfigured states and actionable tests.

- [ ] **Step 5: Verify**

Run:

~~~bash
php artisan test tests/Feature/Settings tests/Feature/Support
npm run e2e -- --grep "integrations|settings|backup"
~~~

Expected: disabled Umami and AI make no outbound requests; backups restore into a fresh <code>/data</code>; support-bundle secret canaries are absent.

- [ ] **Step 6: Commit**

~~~bash
git add app/Support/BackupService.php app/Support/SupportBundleService.php app/Support/UmamiScript.php app/Http/Controllers/IntegrationController.php app/Http/Controllers/SettingsController.php app/Http/Controllers/BackupController.php resources/js/pages/Integrations.tsx resources/js/pages/Settings.tsx resources/js/pages/settings tests/Feature/Settings tests/Feature/Support
git commit -m "feat: complete settings backups and diagnostics"
~~~

### Task 20: Security, Accessibility, Performance, and End-to-End Matrix

**Files:**
- Create: <code>tests/Browser/AccessibilityTest.php</code>
- Create: <code>tests/Integration/Security/FilesystemBoundaryTest.php</code>
- Create: <code>tests/Integration/Security/SecretLeakTest.php</code>
- Create: <code>tests/Integration/Runtime/LegendaryStackSmokeTest.php</code>
- Create: <code>tests/fixtures/minecraft-paper-geyser-floodgate/</code>
- Create: <code>docker-compose.integration.yml</code>
- Create: <code>docs/security/threat-model.md</code>
- Create: <code>docs/operations/test-matrix.md</code>
- Modify: <code>app/Http/Middleware/</code>
- Modify: <code>bootstrap/app.php</code>

**Interfaces:**
- Consumes: completed V1 features.
- Produces: documented threat model, reproducible compatibility matrix, security headers, accessibility evidence, and end-to-end smoke suite.

- [ ] **Step 1: Encode the required matrix**

<code>docs/operations/test-matrix.md</code> must cover:

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

- [ ] **Step 2: Add application security headers and limits**

Set CSP with per-request nonce, <code>frame-ancestors 'none'</code>, <code>object-src 'none'</code>, restrictive <code>connect-src</code> expanded only for configured services, HSTS only under HTTPS, <code>X-Content-Type-Options: nosniff</code>, strict referrer policy, secure/HTTP-only/SameSite cookies, CSRF for browser routes, trusted proxy configuration, upload limits, request-body limits, and rate limits for login, AI, tokens, uploads, API, and MCP.

- [ ] **Step 3: Run a fixture-based integration stack**

The integration Compose stack starts CraftKeeper, a fake bounded RCON service, and a disposable shared Minecraft volume populated from fixtures. It verifies discovery, config proposal/apply/restore, live console, plugin upload/update/rollback, API scope, MCP proposal, optional-service outages, restart-required state, and backup/restore without requiring a production server.

- [ ] **Step 4: Run an opt-in real Legendary stack smoke**

Use <code>05jchambers/legendary-minecraft-geyser-floodgate:latest</code> only in a manually triggered or nightly job. Pin the image digest in the recorded result, accept the EULA only inside the ephemeral test environment, wait for Paper/RCON readiness, exercise read-only discovery and safe <code>list</code>, then destroy the volume. Never run plugin mutation tests against the user’s live server.

- [ ] **Step 5: Execute the complete gate**

Run:

~~~bash
composer test
npm run test
npm run typecheck
npm run build
npm run e2e
docker compose -f docker-compose.integration.yml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f docker-compose.integration.yml down -v
~~~

Expected: all commands exit 0; axe reports no serious/critical violations; no secret canary appears in HTML, JSON, websocket, logs, audit, support bundle, AI transport, or MCP output; performance budgets are under 250 KiB gzipped initial JS and under 2 seconds p95 for cached page responses on the fixture dataset.

- [ ] **Step 6: Commit**

~~~bash
git add app/Http/Middleware bootstrap/app.php tests/Browser tests/Integration tests/fixtures/minecraft-paper-geyser-floodgate docker-compose.integration.yml docs/security docs/operations/test-matrix.md
git commit -m "test: harden CraftKeeper release matrix"
~~~

### Task 21: Documentation, CI/CD, Image Release, and V1 Acceptance

**Files:**
- Create: <code>.github/workflows/ci.yml</code>
- Create: <code>.github/workflows/image.yml</code>
- Create: <code>.github/workflows/release.yml</code>
- Create: <code>.github/dependabot.yml</code>
- Create: <code>SECURITY.md</code>
- Create: <code>CONTRIBUTING.md</code>
- Create: <code>docs/installation/dokploy.md</code>
- Create: <code>docs/installation/docker-compose.md</code>
- Create: <code>docs/operations/rcon.md</code>
- Create: <code>docs/operations/recovery.md</code>
- Create: <code>docs/operations/upgrades.md</code>
- Create: <code>docs/architecture/decisions.md</code>
- Modify: <code>README.md</code>
- Modify: <code>CHANGELOG.md</code>

**Interfaces:**
- Consumes: the complete test matrix and Docker image.
- Produces: protected CI, signed multi-architecture GHCR images, immutable version tags, release notes, SBOM, vulnerability results, and operator documentation.

- [ ] **Step 1: Define CI**

On pull request and push, run separate PHP, frontend, browser, integration, and container jobs. Cache only dependency downloads. Pin actions to full commit SHAs. Upload test reports and screenshots on failure. Require PHP 8.4, Node 22, SQLite, and the exact lockfiles. Cancel superseded branch runs.

- [ ] **Step 2: Define image publication**

On a signed <code>v*</code> tag, build <code>linux/amd64</code> and <code>linux/arm64</code>, run container smoke and vulnerability scan, generate SPDX and CycloneDX SBOMs, attach build provenance, sign the manifest with keyless Sigstore/Cosign, and publish:

~~~text
ghcr.io/carmelosantana/craftkeeper:v1.2.3
ghcr.io/carmelosantana/craftkeeper:v1.2
ghcr.io/carmelosantana/craftkeeper:v1
ghcr.io/carmelosantana/craftkeeper:latest
~~~

Do not publish <code>latest</code> from prerelease tags.

- [ ] **Step 3: Write operator documentation**

Document Dokploy proxy/websocket settings, shared external volume selection, file UID/GID strategy, required <code>APP_KEY</code>, first-run admin, RCON configuration and private networking, AI/Ollama optional setup, optional Umami setup, backup/restore, upgrades/migrations, rollback by image digest, troubleshooting permissions, support bundles, API tokens, MCP OAuth grants, and the absence of Docker control.

- [ ] **Step 4: Run release-candidate acceptance**

Run:

~~~bash
git diff --check
composer test
npm run test
npm run typecheck
npm run build
npm run e2e
docker buildx build --platform linux/amd64,linux/arm64 --provenance=true --sbom=true .
~~~

Expected: clean diff, all gates pass, both image architectures build, migrations succeed from an empty <code>/data</code>, onboarding completes, and all Global Constraints can be mapped to a passing test or documented operator behavior.

- [ ] **Step 5: Perform the manual design acceptance**

Compare every route named in this plan at desktop, tablet, and mobile against the mockups. Verify token values, typography, sidebar and content widths, density, page hierarchy, diff/approval flows, bottom sheets, table-to-card transformation, empty/error/degraded states, keyboard traversal, reduced motion, zoom at 200%, and color contrast. Record accepted deviations with rationale in <code>docs/architecture/decisions.md</code>.

- [ ] **Step 6: Commit**

~~~bash
git add .github SECURITY.md CONTRIBUTING.md README.md CHANGELOG.md docs
git commit -m "docs: prepare CraftKeeper v1 release"
~~~

## V1 Exit Criteria

- Fresh Docker/Dokploy installation reaches onboarding and creates exactly one admin.
- CraftKeeper operates with the Legendary Minecraft stack through the shared volume and private RCON only.
- It discovers and previews recognized and generic config files without escaping <code>/minecraft</code>.
- Guided, structured, and source edits converge on a validated, diffed, approved, snapshotted, atomic, audited operation.
- External file changes result in conflict resolution rather than overwrite.
- Server state, players, console, logs, activity, and graceful stop work; RCON failure is isolated.
- Plugin discovery, manual upload, inspection, compatibility evidence, install, update, disable, remove, restart-required state, and rollback work.
- CraftKeeper Catalog, Hangar, and Modrinth degrade independently.
- AI is entirely optional; Ollama/OpenAI-compatible outages do not affect core operation.
- Hosted AI input is demonstrably redacted and disclosed; AI cannot approve its own actions.
- REST API v1 is documented, scoped, rate-limited, idempotent, and secret-safe.
- MCP uses OAuth grants, exposes only documented capabilities, and cannot approve mutations.
- Umami is disabled by default and cannot block any application path.
- Backups and support bundles restore or export without secret leakage.
- All mocked and specified pages meet the responsive and accessibility criteria.
- CI builds, tests, scans, signs, and releases multi-architecture images from tags.

## Explicitly Deferred Beyond V1

- Multiple Minecraft servers
- Docker socket/container lifecycle management
- Autonomous or scheduled AI actions
- External MCP mutation approval
- Companion Minecraft plugin
- NBT, player data, region, or world editing
- Full observability platform, Prometheus, distributed tracing, or indefinite log retention
- Webhooks
- Dynamic MCP client registration
- Plugin code execution or sandbox analysis
- Built-in billing, license enforcement, or donor entitlement checks

## Primary References for the Implementer

- Local visual source of truth: <code>Design/</code>
- Local handoff: <code>Design/handoff/README.md</code>, <code>components.json</code>, <code>design-tokens.json</code>, and <code>pages.json</code>
- Laravel 13: https://laravel.com/docs/13.x
- Laravel React starter kit: https://laravel.com/docs/13.x/starter-kits
- Inertia Laravel adapter: https://inertiajs.com/server-side-setup
- Laravel Reverb: https://laravel.com/docs/13.x/reverb
- Laravel Sanctum: https://laravel.com/docs/13.x/sanctum
- Laravel Passport: https://laravel.com/docs/13.x/passport
- Laravel MCP: https://laravel.com/docs/13.x/mcp
- php-agents: https://github.com/carmelosantana/php-agents
- Paper configuration: https://docs.papermc.io/paper/reference/
- Geyser configuration: https://geysermc.org/wiki/geyser/setup/
- Floodgate setup: https://geysermc.org/wiki/floodgate/setup/
- Hangar API: https://hangar.papermc.io/api-docs
- Modrinth API: https://docs.modrinth.com/api/

## Execution Notes

- Start execution in a git worktree created with the <code>using-git-worktrees</code> skill.
- Use <code>subagent-driven-development</code> for task-by-task implementation and two-stage review, or <code>executing-plans</code> for checkpointed inline execution.
- The implementer should update checkbox state in this document only after the exact verification command has produced the expected result.
- If a current upstream API differs from this plan, record the verified upstream version and the smallest necessary adjustment in <code>docs/architecture/decisions.md</code>; do not weaken a security or approval boundary.
