# CraftKeeper threat model

Task 20's required trust-boundary documentation. This is a description of
what the CODE already does (verified against the source, not aspirational)
— every claim below names the enforcing class so it stays checkable.

## Trust boundaries — what is untrusted, and where trust begins

CraftKeeper mediates between an administrator and a Minecraft server it
does not fully control. Several inputs cross a trust boundary before
CraftKeeper acts on them; each is treated as untrusted at the point it
enters, and validated/bounded/redacted before it can affect anything.

| Input | Untrusted because | Where trust begins |
|---|---|---|
| Browser (Inertia requests) | Any authenticated session could be a stolen cookie or a compromised admin browser; CSRF must be assumed possible without the token check | `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` (Laravel's CSRF verification, in the `web` middleware group by default) + session auth (`auth` middleware) gate every mutating route |
| REST API (`/api/v1/*`) | A bearer token could be leaked/replayed from outside the browser session entirely | `App\Http\Middleware\EnsureApiScope` (scope-checked per route) + Sanctum token auth + `App\Providers\AppServiceProvider::configureApiRateLimiting()`'s `api` limiter |
| MCP (`/mcp/craftkeeper`) | An AI agent (the MCP client) is explicitly a LESS trusted actor than the human administrator — it can be manipulated by adversarial tool output/prompt injection | `auth:passport` (OAuth bearer token) + `App\Mcp\Support\McpGuard` (per-call grant/scope authorization + full audit trail) on every tool/resource/prompt call, no exceptions |
| Catalog sources (Hangar/Modrinth/the CraftKeeper Catalog JSON) | Three independent third-party HTTP services CraftKeeper does not operate | `App\Catalog\Transport\CatalogHttpClient` (bounded timeouts/retries/response size — `config/catalog.php`) + `resources/catalog/plugin-catalog.schema.json` contract validation; a source being down/slow/wrong-shaped degrades to "unavailable," never a crash or an unvalidated install |
| A plugin JAR (uploaded or downloaded) | Executable code from an operator (upload) or a third-party catalog (download) — either could be malicious or simply corrupt | `App\Plugins\JarInspector` (declared-size-then-actual-bytes streaming inspection, quarantine directory, SHA-256 computed during streaming) before anything is placed under the real `plugins/` directory; a mismatch (`App\Plugins\Exceptions\PluginChecksumMismatch`) refuses the install outright |
| Application/console logs | The MINECRAFT SERVER's own stdout (tailed by `App\Server\LogTailService` into `ConsoleEntry`) is free-form text from a process CraftKeeper does not fully control (plugins can print anything) | Bounded/sanitized at the tail step (ANSI/control-sequence stripping, a 16 KiB per-line cap — see `App\Events\ConsoleEntryReceived`'s own docblock) — NOT secret-redacted, a documented, accepted boundary (see "Known, accepted residual risk" below) |
| AI input (a chat message, or a tool-call the AI model itself proposes) | The user's own typed text can accidentally contain a secret; the MODEL's output (tool-call arguments, chat replies) is adversarial-influenceable by whatever context it was given, including a compromised/malicious hosted provider | `App\Ai\SecretRedactor` scrubs every outgoing request (system prompt, full history, current message) against every known Secret/schema-secret value before it ever reaches a non-Ollama-opted-in transport (`App\Ai\AssistantService::redactRequest()`); the AI can only ever PROPOSE an Operation (see below), never apply one |

## Structural guarantees (not just per-input validation)

- **Only services create Operations.** Every mutating action in this
  application — a config change, an RCON command, a plugin
  install/update/disable/remove/rollback, a server stop — becomes an
  `App\Models\Operation` row created through `App\Operations\
  OperationService`, never written directly by a controller, an MCP
  tool, or the AI assistant. This is the one place risk
  (`App\Operations\OperationRisk`), redacted input, and audit trail are
  computed, so there is exactly one code path to review for "how does a
  mutation get recorded," not N per-feature ones.
- **Only approved operations mutate.** `App\Operations\
  OperationHandlerRegistry` + each `App\Operations\Handlers\*Handler`
  only ever runs against an `Operation` whose `status` is
  `OperationStatus::Approved` — a `Proposed` operation is inert data
  until an explicit approval action (a human clicking Approve in the
  UI, or an MCP client calling `approve_operation` with the `*:apply`
  scope a read-only/propose-only grant does not have —
  `App\Mcp\Support\McpScopeConsequences`) flips it. The AI assistant
  itself is never granted an apply/approve scope over the wire it uses
  (see "MCP cannot approve" below) — it can only produce a `Proposed`
  operation for a human (or a separately-scoped MCP client) to approve.
- **Filesystem containment.** Every path CraftKeeper reads or writes
  under the mounted Minecraft directory goes through
  `App\Filesystem\MinecraftPath::fromUserInput()` — canonicalizes the
  configured root, rejects any `..` segment, absolute path, NUL byte, or
  reserved device name outright, then walks the path resolving symlinks
  segment-by-segment and requires every resolved ancestor to stay inside
  the canonical root; the final target (if it exists) must be a regular
  file (`App\Filesystem\Exceptions\NotARegularFile` otherwise — a
  directory, FIFO, socket, or device is refused). `reverifyContainment()`
  re-runs this immediately before disk I/O as TOCTOU mitigation.
  Task 20's `tests/Integration/Security/FilesystemBoundaryTest.php`
  proves this holds end-to-end through three independent real callers
  (the web config editor, the REST API, and an MCP resource) — and, in
  the course of writing that test, found and fixed a real bug: both
  `App\Http\Controllers\ConfigController` and `App\Http\Controllers\
  Api\V1\ConfigController`'s own `resolvePath()` only caught
  `UnsafeMinecraftPath` around `MinecraftPath::fromUserInput()`, missing
  that the same call can also throw `NotARegularFile` directly — an
  existing FIFO/device file under the Minecraft root previously produced
  an unhandled 500 instead of the intended 404. Fixed in both
  controllers as part of this task.
- **RCON bounds.** `App\Console\MinecraftRconClient` is a fresh,
  short-lived connection per command: a hostile length header is
  range-checked (10..1 MiB) BEFORE any bytes beyond the 4-byte header are
  read; a multi-packet response is capped at 1 MiB total and 10,000
  packets; auth failure and timeouts are typed, terminal exceptions, never
  silent hangs or infinite retries. MCP's own RCON tool
  (`App\Mcp\Tools\RunSafeRcon`) additionally only allows a
  fixed allow-list of read-only "safe" commands
  (`App\Console\CommandPolicy`) — an MCP client can never send an
  arbitrary RCON command, safe or not.
- **No secrets to external AI.** Covered above under "AI input" and
  proven end-to-end (a real, generated canary, not a code inspection
  alone) by `tests/Integration/Security/SecretLeakTest.php`'s "AI
  transport body" case, reusing the exact `MockHttpClient`-capturing
  pattern `tests/Feature/Ai/AiRedactionAndInjectionTest.php` established.
- **MCP cannot approve.** `App\Support\ApiScope`/`App\Mcp\Support\
  McpScopeConsequences` define the scopes an MCP OAuth grant can hold;
  `propose_config_change`/`propose_plugin_operation`-style tools only
  ever CREATE a `Proposed` operation. `approve_operation` exists as a
  DISTINCT tool requiring a DISTINCT, separately-consented `*:apply`
  scope — an MCP client scoped only for proposing (the expected,
  documented posture for an AI agent — "AI proposes; the administrator
  approves," `resources/js/features/command-palette/CommandPalette.tsx`'s
  own review-flow copy) is denied by `App\Policies\McpGrantPolicy` if it
  ever attempts to approve/apply anything itself. See
  `tests/Feature/Mcp/McpAuthorizationTest.php`'s scope-denial coverage.

## Known, accepted residual risk

- **Minecraft console output is not secret-scrubbed.** Unlike every
  CraftKeeper-owned secret (RCON password, AI API key, schema-flagged
  config values — all covered by `App\Ai\SecretRedactor`/`App\Config\
  ConfigDiffBuilder`/`App\Support\SecretRedactor`), the Minecraft
  server's own stdout (tailed into `ConsoleEntry`, broadcast verbatim by
  `App\Events\ConsoleEntryReceived`) is free-form text CraftKeeper does
  not control the shape of — a plugin or player could cause the console
  to print anything, including something that happens to look like a
  secret. Scrubbing arbitrary console text for secret-shaped patterns
  would require the same kind of best-effort heuristic
  `App\Console\CommandPolicy::redactedDisplay()` already applies to
  OUTGOING commands (which IS in CraftKeeper's control) — extending that
  heuristic to INCOMING console text is out of this task's scope and is
  recorded here as a deliberate, documented boundary rather than a silent
  gap.

  The one in-scope round-trip this boundary actually produces: an admin
  runs a command through CraftKeeper's own console; Paper echoes that
  command back into its own `latest.log`; `App\Server\LogTailService`
  tails the new line and `App\Events\ConsoleEntryReceived` broadcasts it
  — VERBATIM, including any secret-shaped text the admin typed — on the
  admin-only private `server.console` channel. The SAME command, on its
  way to being persisted for the audit trail, IS redacted by
  `App\Console\CommandPolicy::redactedDisplay()` first. That asymmetry
  (broadcast verbatim, audit-log redacted) is intentional, not an
  oversight: the audit trail is a durable, queryable record that
  outlives the session, while the console channel is a real-time mirror
  of output CraftKeeper already can't control the shape of in the
  general case (see above) — redacting only the one path CraftKeeper
  actually originates (its own outgoing command) is the boundary this
  task draws.

  `tests/Integration/Security/SecretLeakTest.php` encodes both halves of
  this explicitly: its `OperationUpdated` broadcast-payload test targets
  the CraftKeeper-owned operation-progress channel (a strict scalar
  allow-list that structurally cannot carry a secret), and a second,
  dedicated test seeds a real `ConsoleEntry` with a secret-shaped canary
  and asserts the DOCUMENTED behavior directly — the canary reaches
  `ConsoleEntryReceived::broadcastWith()` verbatim (accepted, not a
  leak), while the same string passed through
  `CommandPolicy::redactedDisplay()` is redacted — rather than only
  asserting something narrower about `OperationUpdated` and leaving the
  actually-free-form channel unverified.
- **TOCTOU on filesystem containment** is mitigated (`reverifyContainment()`
  immediately before I/O) but not eliminated — a mounted volume could, in
  principle, be swapped out between the check and the read/write on a
  network filesystem with unusual timing. Documented in
  `App\Filesystem\MinecraftPath`'s own docblock; unchanged by this task.
- **Trusted-proxy configuration is opt-in (`TRUSTED_PROXIES`).** Left
  unset (the default), `$request->isSecure()` reflects the raw
  connection, not `X-Forwarded-Proto` — meaning HSTS and the
  auto-secure-cookie behavior never activate behind a reverse proxy
  until an operator explicitly configures it. This is a deliberate
  default (trusting no proxy is safer than trusting the wrong one) that
  requires one environment variable to correct in a real deployment —
  see `.env.example`'s own comment and `compose.example.yml`.

## Security headers (Task 20)

- `App\Http\Middleware\ContentSecurityPolicy` — a per-request nonce'd
  CSP on every `web`-group response. `frame-ancestors 'none'` and
  `object-src 'none'` unconditionally; `script-src 'self' 'nonce-...'`
  plus the Umami origin (`App\Support\UmamiScript::allowedOrigin()`)
  when analytics is enabled; `connect-src 'self'` plus the Reverb
  websocket origin, the currently-active AI provider's origin, the three
  catalog source origins, and Umami's origin when enabled — never a
  wildcard, never an origin for a service that isn't actually configured
  right now. `style-src 'self' 'unsafe-inline'` is the one deliberate,
  documented relaxation (see that middleware's own docblock for exactly
  why — Sonner/Radix inject literal `<style>` elements with no nonce
  support, and inline STYLE injection is a materially lower-severity
  concern than inline SCRIPT injection, which stays strictly nonce'd).
- `App\Http\Middleware\SecurityHeaders` — `X-Content-Type-Options:
  nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Frame-Options: DENY` on every response (web, API, and MCP alike);
  `Strict-Transport-Security` only when `$request->isSecure()`.
- CSRF, secure/HTTP-only/SameSite cookies — already Laravel defaults in
  this app (`PreventRequestForgery` in the `web` group;
  `config/session.php`'s `http_only=true`/`same_site=lax`/`secure=null`
  which auto-activates over HTTPS via `TrustProxies`) — verified present,
  not newly added.
- Rate limits — `login`/`two-factor`/`passkeys` (pre-existing, Fortify);
  `api` (pre-existing, Task 17); `ai`/`uploads`/`tokens`/`mcp` (Task 20,
  `App\Providers\AppServiceProvider::configureApiRateLimiting()`).
- Upload/body-size limits — `docker/php/uploads.ini` +
  `docker/nginx/default.conf`'s `client_max_body_size`, both raised to
  match `config/craftkeeper.php`'s `PLUGIN_MAX_ARTIFACT_BYTES` default
  (previously mismatched: nginx capped at 20 MiB, PHP's own uncustomized
  defaults at 2–8 MiB, silently rejecting any real plugin upload over a
  few MiB before this task).

See `docs/architecture/decisions.md` (Task 20) for the full contrast
audit, CSP verification method, and secret-leak-matrix results.
