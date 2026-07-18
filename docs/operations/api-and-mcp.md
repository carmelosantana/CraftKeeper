# API tokens and MCP OAuth grants

CraftKeeper exposes two integration surfaces beyond the web UI — a
versioned REST API (`/api/v1`) and an MCP server (`/mcp/craftkeeper`) for
AI agents. Both are managed from **Integrations** (API and MCP pages)
and both are built on the same nine-scope vocabulary
(`App\Support\ApiScope`): `server:read`, `config:read`, `config:propose`,
`config:apply`, `plugins:read`, `plugins:manage`, `activity:read`,
`rcon:safe`, `rcon:admin`. Read access never implies write, apply, or
RCON access — each must be granted explicitly.

## REST API tokens (Integrations > API)

1. Create a token, name it, and select exactly the scopes it needs.
   Copy the plaintext value shown once — CraftKeeper stores only its
   hash (Sanctum `personal_access_tokens`) and cannot show it to you
   again.
2. Use it as a bearer token against `/api/v1/*` — the full contract is
   published as `openapi.yaml` (also viewable from the running
   application's Integrations > API page).
3. Every mutating endpoint still creates a **proposed** `Operation` — a
   token scoped `config:propose` or `rcon:safe` can create a proposal,
   never execute it. `config:apply` can call the apply endpoint, but only
   against an operation a human has already approved (`Laravel\Passport`
   isn't involved here — Sanctum's own guard structurally cannot satisfy
   `OperationService::approve()`'s `User`-typed parameter, so no API
   token, regardless of scope, can ever approve anything itself).
4. Requests are rate-limited (`api`, plus dedicated `ai`/`uploads`/
   `tokens` limiters for those specific endpoint groups) and support an
   `Idempotency-Key` header for safe retries of a mutating request — a
   retried key with an identical body returns the original operation;
   a retried key with a different body is rejected (`409`) rather than
   silently creating a second one or returning a mismatched result.
5. Revoke a token instantly from the same page — revocation takes effect
   immediately (no token caching layer to wait out).

## MCP OAuth grants (Integrations > MCP)

MCP is how an AI agent (Claude, or any MCP-speaking client) connects to
CraftKeeper — and it is treated as a **less** trusted actor than a human
administrator or even a REST API token, because its inputs (and its own
tool-call arguments) can be influenced by prompt injection from content
it reads elsewhere.

1. From Integrations > MCP, create a grant: this provisions both an
   OAuth client and an `McpGrant` row in one step, with a scope
   **ceiling** you set here — independent of whatever scopes a
   connecting client later asks for during the OAuth dance.
2. Hand the resulting client ID to your MCP client software. It
   authenticates via a real `/oauth/authorize` consent flow
   (authorization code + mandatory PKCE — every OAuth client this
   application creates is public, no client secret, matching the MCP
   spec's expectations). There is no dynamic client registration and no
   device-code flow — both are closed off entirely (no route exists for
   either), not merely access-denied.
3. **The grant's own scope ceiling wins**, always — even if a connecting
   client's OAuth `scope=` request and an admin's consent click would
   otherwise allow more, CraftKeeper's tool/resource authorization checks
   the `McpGrant` row exclusively, never the live token's own claims.
4. The exposed tool surface is closed and small, by design: exactly
   three tools exist — `propose_config_change`, `propose_plugin_operation`
   (disable/remove only; install/update are web-UI-only in V1, see
   `CHANGELOG.md`'s "Known limitations"), and `run_safe_rcon` (the same
   fixed safe-command allow-list the web Console uses). **There is no
   `approve_operation` tool at all** — not a permission check that
   happens to deny it, but a tool that does not exist in the server's
   registered tool list, so an MCP client can never approve anything it
   or anyone else proposed, regardless of scope.
5. Revoke a grant from the same page; a revoked or expired grant is
   denied on its very next call, not just at its next token refresh.

## Where to look for more detail

- `openapi.yaml` — the full REST contract, kept in sync with the actual
  route table by `tests/Contract/Api/OpenApiTest.php`.
- `docs/security/threat-model.md` — "MCP cannot approve" and the
  structural (not just tested) reasons why, plus the REST API's
  equivalent guarantee.
- `docs/architecture/decisions.md` (Tasks 17–18) — the specific
  implementation findings behind both surfaces, including a real
  Sanctum session-guard bypass that was found and fixed during
  development (`Laravel\Sanctum\TransientToken::can()` returning `true`
  unconditionally) and the OAuth/PKCE/dynamic-registration decisions
  above.
