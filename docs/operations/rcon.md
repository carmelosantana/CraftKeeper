# RCON configuration and private networking

CraftKeeper controls your Minecraft server exclusively through RCON
(Remote Console) — never through the Docker socket, never by sending
signals to a process. This page covers enabling RCON on the Minecraft
server side and keeping it private on the network side; for the
command-policy and audit behavior on CraftKeeper's side, see
`docs/security/threat-model.md`'s "RCON bounds" section.

## 1. Enable RCON on the Minecraft server

In your Minecraft server's `server.properties` (discoverable and
editable directly from CraftKeeper's Configurations page once the
Minecraft directory is mounted):

```properties
enable-rcon=true
rcon.port=25575
rcon.password=<a long, random, unique password>
```

- **Choose a strong, unique password.** It is never reused for anything
  else in CraftKeeper and is stored encrypted (`App\Models\Secret`,
  Laravel's `encrypted` Eloquent cast, AES-256-GCM under your
  application's `APP_KEY`) — never logged, never sent to an AI provider,
  never included in an API/MCP response or audit event in plaintext.
- **Keep the RCON port private.** Do not publish `rcon.port` to the host
  (no `-p 25575:25575` / no `ports:` entry for it in your Minecraft
  server's Compose service) and do not open it on any firewall. RCON has
  no rate limiting or brute-force protection of its own — its only real
  defense is not being reachable from anywhere except CraftKeeper.
  Because CraftKeeper and your Minecraft server share the same Docker
  Compose network (or can be placed on one), CraftKeeper only ever needs
  to reach `rcon.host:rcon.port` over that private, container-to-container
  network — never over a publicly routable address.

## 2. Point CraftKeeper at it

During first-run onboarding (the "RCON setup" step) or later from
Settings > Server, provide:

- **RCON host** — the Minecraft server container's **service name** on
  your Compose network (e.g. `minecraft`), not `localhost` and not a
  public hostname. Docker Compose's embedded DNS resolves service names
  to the right container automatically as long as both services share a
  network — CraftKeeper's own container never needs a static IP.
- **RCON port** — matching `rcon.port` above (default `25575`).
- **RCON password** — matching `rcon.password` above.

Both onboarding and Settings run the exact same test-connection action
before saving, and both store the value the same way
(`Setting::put('rcon.host', ...)` / `Setting::put('rcon.port', ...)` /
`Secret::put('rcon.password', ...)` — host/port are plain settings, only
the password is a `Secret`). RCON is optional at onboarding time ("Skip
for now" is always available) — CraftKeeper works with RCON absent, just
with RCON-dependent features (start/stop, live console, some player
data) reporting a clearly labeled degraded state rather than failing the
whole page.

## 3. What CraftKeeper will and will not send over RCON

- **Safe, predefined actions** (`list`, `save-all flush`, `say`,
  `time query daytime`, `weather query`) can be proposed and approved in
  one step from the Console UI.
- **Elevated commands** (`stop`, `op`/`deop`, `ban`, `whitelist`,
  `gamerule`, a raw `execute`, and anything else `App\Console\
  CommandPolicy` doesn't recognize as one of the safe actions above)
  always show a consequence panel and require a fresh approval — never a
  one-click send.
- A **graceful stop** always runs `save-all flush` before `stop`, then
  reports "waiting for the Minecraft container's restart policy" and
  polls RCON until it goes unavailable and comes back healthy.
  CraftKeeper never calls Docker to restart the container itself — that
  is your Compose `restart:` policy's job.
- The REST API and MCP can only ever **propose** an RCON command (never
  self-approve-and-execute, even for a "safe" one) — a human always
  approves in the web Console, exactly as if the command had been typed
  there directly.

## 4. Troubleshooting

CraftKeeper reports one of these states for RCON specifically (visible
on Overview, Server, and Integrations, and included in the support
bundle's `health.json` — see `docs/operations/recovery.md`):

| State | Meaning | What to check |
|---|---|---|
| Disabled | No RCON host/password configured yet | Run onboarding's RCON step again, or Settings > Server |
| Bad credentials | Connected, authentication rejected | `rcon.password` in `server.properties` doesn't match what's stored in CraftKeeper — update one to match the other |
| Unavailable | Connection refused or timed out | Minecraft server not running yet, `enable-rcon=false`, wrong host/port, or the two containers aren't on the same Docker network |
| Delayed | Slow to respond, within timeout | Usually transient (server under load); CraftKeeper retries with jittered backoff up to a 60-second ceiling |
| Healthy | Working normally | — |

An RCON outage never takes down the rest of the application — it only
degrades the specific cards/actions that depend on it (see
`docs/security/threat-model.md`'s trust-boundary table). Full protocol
details (packet format, timeouts, size limits) live in
`App\Console\MinecraftRconClient`'s own docblock, verified in
`tests/Unit/Console/MinecraftRconClientTest.php` and, opt-in, against a
real Paper+Geyser+Floodgate server in
`tests/Integration/Runtime/LegendaryStackSmokeTest.php`.
