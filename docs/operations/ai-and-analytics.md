# Optional AI provider and analytics setup

Both integrations on this page are **entirely optional**. CraftKeeper
works fully with neither configured — no onboarding step, page, request,
or build depends on either being present (see `docs/operations/
v1-acceptance.md`, Global Constraints 8–9).

## AI provider (Settings > AI Providers, or onboarding's AI step)

CraftKeeper supports exactly two provider shapes:

- **Ollama** — a local or self-hosted Ollama instance, OpenAI-compatible
  API surface. No API key required.
- **Hosted (any OpenAI-compatible provider)** — OpenAI itself, or any
  other service exposing an OpenAI-compatible chat completions endpoint.
  Requires a base URL, a model name, **and** an API key — a hosted
  provider is never attempted without a key configured.

| Setting | Applies to | Key |
|---|---|---|
| Provider selection | both | `ai.provider` (`"ollama"` selects Ollama; any other non-empty value selects the hosted slot) |
| Base URL | Ollama | `ai.ollama.base_url` (default `http://localhost:11434/v1`) |
| Model | Ollama | `ai.ollama.model` (default `llama3.2`) |
| Allow unredacted context | Ollama only | `ai.ollama.allow_unredacted` — see below |
| Base URL | Hosted | `ai.hosted.base_url` |
| Model | Hosted | `ai.hosted.model` |
| API key | Hosted | `ai.api_key` (stored as an encrypted `Secret`, never a plain `Setting`) |

**Redaction.** Every outgoing hosted-provider request has every
currently-known secret value (RCON password, any configured API key,
schema-flagged config fields) scrubbed before transmission, and
CraftKeeper discloses exactly what was redacted back to you in the
Assistant UI — see `docs/security/threat-model.md`'s "AI input" row.
**Ollama is the one place this can be relaxed**, via `ai.ollama.
allow_unredacted` — intended for a genuinely local/self-hosted Ollama
instance where you trust the destination as much as CraftKeeper itself
(e.g. running on the same host or private network). Leave this off
unless you specifically understand and accept that trade-off; a hosted
provider can never have this relaxed regardless of this setting.

**What the AI can and cannot do.** The assistant can propose a
configuration change, a plugin operation (disable/remove), or a safe
RCON command — every one of these becomes a `Proposed` operation a human
must separately review and approve before anything is written to disk
or sent to your Minecraft server. There is no path, in this version, for
the AI to approve its own proposal or take an autonomous action (see
`docs/security/threat-model.md`'s "MCP cannot approve" section — the
same approval boundary applies to the built-in Assistant, not just
external MCP clients).

**Degraded behavior.** An unreachable or misconfigured provider (Ollama
down, a hosted provider returning an error, no provider configured at
all) shows as a clearly labeled unavailable/degraded state on the
Assistant page and the Integrations overview — it never affects
configuration, plugins, RCON, the REST API, or MCP. **Disclosed gap:**
the AI provider adapter does not currently distinguish a 401
(bad key) from a 429 (rate limited) from a timeout — all three currently
collapse to the same generic "unavailable" signal (see `CHANGELOG.md`).

## Umami analytics (Settings > Analytics)

Disabled by default. When you choose to enable it:

| Setting | Key |
|---|---|
| Enabled | `analytics.umami.enabled` |
| Script URL (must be `https://`) | `analytics.umami.script_url` |
| Website ID | `analytics.umami.website_id` |

All three must be present and the script URL must parse as a valid
`https://` URL — any incomplete or invalid combination is treated
identically to "disabled": CraftKeeper renders no `<script>` tag at all
rather than a broken or insecure one. There is no CraftKeeper-side proxy
or backend call involved — the browser loads the script directly from
the URL you configure, and the Content-Security-Policy's `script-src`/
`connect-src` are widened to that exact origin only when analytics is
actually enabled (see `docs/security/threat-model.md`'s CSP section).

Umami being unreachable, misconfigured, or simply not your thing has no
effect on any other part of the application — no route, redirect, or
build step in CraftKeeper depends on it.
