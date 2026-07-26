# CraftKeeper Compose Generator — Design

**Date:** 2026-07-26
**Status:** Approved (brainstorm), pending spec review
**Author:** Carmelo Santana

## Summary

A self-contained, zero-build static web tool that lets anyone assemble a
complete Docker Compose stack for CraftKeeper — CraftKeeper itself alongside
a Legendary Java Minecraft (Geyser + Floodgate) server, an optional plugin
updater, and an optional File Browser — and download a ready-to-run bundle.
The tool encodes the exact, vetted service definitions from a known-good
production stack so that the generated file works on the first
`docker compose up`.

It lives at `generator/` inside the CraftKeeper repository and is published
to GitHub Pages. It has no backend, no login, and requires no install — the
whole point is to help people who have *not yet* installed CraftKeeper
produce the compose file that first runs it.

## Goals

- Produce a `compose.yml` that is valid and runs without hand-editing.
- Generate the accompanying secrets and manual-step instructions so the user
  reaches a working panel without hunting through docs.
- Stay opinionated by default (short form) while giving power users an
  advanced drawer.
- Be trivially hostable and long-lived: open `index.html` and it runs, with a
  tiny, vendored dependency surface.

## Non-Goals

- No server-side rendering, persistence, or accounts.
- No arbitrary compose editing — the tool knows a fixed set of services and
  their fields, not a free-form YAML editor.
- No management of an already-running CraftKeeper instance (that is the app's
  own job). This tool is strictly a cold-start authoring aid.
- No support for orchestrators other than Docker Compose in v1.

## Decisions (locked during brainstorm)

| Fork | Decision |
|---|---|
| Placement / audience | Static, decoupled from Laravel; `generator/` folder in the CraftKeeper repo; deployed via GitHub Pages. Audience: anyone spinning up a new stack, pre-install. |
| Config scope | Moderate: opinionated core form + a collapsible advanced drawer. |
| Output | Full bundle — `compose.yml` + `.env` + `README.md` + next-steps — downloadable as a zip, with per-file copy. |
| Tech | Zero-build: plain JavaScript ES modules (JSDoc for editor types), no transpile step. |
| Styling | Hand-authored, committed `styles.css` (CraftKeeper's dark look). No Tailwind CDN, no external style dependency. |
| Zip | Vendored `fflate` (~8 KB), pinned to a specific version with a provenance comment. |

## Service model

The tool knows exactly four services. Their defaults mirror the canonical
`compose.legendary.yml` and the corrected production stack.

### craftkeeper (always included)

- `image: ghcr.io/carmelosantana/craftkeeper:<pinned tag>` — default pinned to
  the current release (`v1.1.5` at time of writing; the tag is a single
  constant in `services.js` so bumping it is a one-line change).
- `container_name: craftkeeper`, `restart: unless-stopped`,
  `depends_on: [minecraft]`.
- Volumes: `minecraft:/minecraft` (shared with the server) and
  `craftkeeper_data:/data`.
- Environment: `APP_URL` (from the domain field), `APP_KEY`
  (`${CRAFTKEEPER_APP_KEY}`), `MINECRAFT_ROOT`, `DATA_ROOT`, `DB_CONNECTION`,
  `DB_DATABASE`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`,
  `BROADCAST_CONNECTION`.
- Healthcheck: `curl --fail --silent http://127.0.0.1:8080/up`.
- **Core fields:** memory is not a CraftKeeper field; domain/APP_URL is.
- **Advanced fields:** published host port (default commented out, assuming a
  proxy), `TRUSTED_PROXIES`, `TRUSTED_HOSTS`, and the realtime/Reverb block
  (`BROADCAST_CONNECTION: reverb` + `REVERB_APP_ID` + `REVERB_APP_KEY` +
  generated `REVERB_APP_SECRET`). Default `BROADCAST_CONNECTION: log`.

### minecraft (always included)

- `image: 05jchambers/legendary-minecraft-geyser-floodgate:latest`.
- Ports: `25565:25565`, `19132:19132`, `19132:19132/udp`.
- Volume: `minecraft:/minecraft`. `stdin_open` + `tty`, the Legendary
  entrypoint, and env `Port`, `BedrockPort`, `TZ`, `MaxMemory`, `QuietCurl`.
- RCON (25575) is intentionally NOT published to the host — CraftKeeper reaches
  it over the compose network.
- **Core fields:** `MaxMemory` (memory), `TZ` (timezone).
- **Advanced fields:** `deploy.resources` CPU/memory limits + reservations;
  optional version pin.
- When `plugin-updater` is enabled, this service gains
  `depends_on: { plugin-updater: { condition: service_completed_successfully } }`.

### plugin-updater (optional toggle)

- `image: ghcr.io/carmelosantana/minecraft-plugin-updater:latest`,
  `pull_policy: always`, `restart: "no"`, volume `minecraft:/minecraft`.
- Enabling it also rewires `minecraft.depends_on` (above).

### filebrowser (optional toggle, default OFF)

- Defaults to disabled — the least essential of the four services. When the
  toggle is off, the service and its two volumes are omitted entirely.
- `image: filebrowser/filebrowser:latest`, `restart: unless-stopped`,
  port `8081:80`, volumes `minecraft:/srv/minecraft`, `filebrowser_config`,
  `filebrowser_database`, env `PUID`/`PGID`/`TZ`, and the `--config`/`--database`
  /`--root` command.
- **Advanced fields:** published host port (default `8081`), `PUID`/`PGID`.

### Volumes block — derived, never hand-written

The top-level `volumes:` block is **computed from the enabled services**, not
authored by hand. `minecraft` and `craftkeeper_data` are always present;
`filebrowser_config` and `filebrowser_database` appear only when File Browser
is enabled. This structurally prevents the class of bug that motivated this
project: a service mounting a named volume that the `volumes:` block never
declares (which makes `docker compose up` fail outright).

## Architecture

Everything lives under `generator/`. The knowledge model and generators are
pure and DOM-free so they can be unit-tested in Node; the DOM layer is thin.

```
generator/
  index.html            # shell: form (left), tabbed live preview (right), action bar
  styles.css            # committed dark stylesheet
  main.js               # DOM wiring only: form <-> state, recompute, render, copy/download
  lib/
    services.js         # service descriptors: defaults + core/advanced field metadata
    state.js            # default config state + normalization
    compose.js          # buildCompose(state) -> string  (derives volumes)
    env.js              # buildEnv(state) -> string       (embeds generated secrets)
    readme.js           # buildReadme(state) -> string    (tailored to enabled services)
    steps.js            # buildNextSteps(state) -> string (up cmd, RCON edit, URL)
    secrets.js          # Web Crypto: appKey(), rconPassword(), reverbSecret()
    yaml.js             # small structured-string helpers (indent, quote, block)
    bundle.js           # file map -> zip (Blob) via vendored fflate
  vendor/
    fflate.min.js       # pinned, provenance comment at top
  tests/
    compose.test.js     # golden + volume-derivation
    env.test.js         # secret formats, consistency
    steps.test.js       # RCON password matches .env
    services.test.js    # descriptor invariants
```

### Data flow

1. `state.js` holds one plain config object (defaults from `services.js`).
2. `main.js` binds form controls to that object; every `input`/`change` event
   updates state and calls a single `recompute()`.
3. `recompute()` calls the four pure builders and writes their output into the
   preview tabs. No builder touches the DOM.
4. Secrets are generated once on load (and on an explicit "regenerate" button),
   held in state, and consumed by `env.js`, `steps.js`, and — for Reverb —
   `compose.js`. One password value, referenced everywhere, so `.env` and the
   server.properties instruction can never disagree.
5. Download assembles `{ 'compose.yml', '.env', 'README.md' }` (README carries
   the next-steps content as a section) into a zip via `bundle.js`.

### YAML generation

Built with structured string helpers in `yaml.js`, not a YAML library — the
document shape is fixed and small, and avoiding a parser keeps the dependency
surface at exactly one vendored file. Indentation and quoting correctness is
guarded by the golden test, which compares full output against a committed
known-good file.

### Secrets

- `APP_KEY`: `base64:` + `crypto.getRandomValues(new Uint8Array(32))`
  base64-encoded — the Laravel app-key format.
- RCON password + `REVERB_APP_SECRET`: URL-safe random strings from the same
  CSPRNG.
- Generated entirely client-side; nothing is transmitted anywhere.

## Testing

- Runner: `node --test` (Node's built-in runner) — zero test dependencies,
  consistent with the zero-build stance. The `lib/*.js` modules are standard
  ES modules and import directly into Node.
- **Golden test:** a "matches the reference stack" preset (CraftKeeper +
  minecraft + plugin-updater + filebrowser, the production values) must
  reproduce a committed `tests/fixtures/reference-compose.yml`, which is the
  corrected stack validated during this project's brainstorm.
- **Volume-derivation test:** toggling File Browser off removes
  `filebrowser_config`/`filebrowser_database` from the `volumes:` block and
  from the service list; core volumes always remain.
- **Consistency test:** the RCON password embedded in `.env` is byte-identical
  to the one in the next-steps `server.properties` instruction.
- **Secret-format tests:** `APP_KEY` starts with `base64:` and decodes to 32
  bytes; generated passwords are non-empty and URL-safe.
- The DOM layer (`main.js`) is kept thin enough that it needs no automated
  test in v1; it is exercised manually. A Playwright smoke test is a possible
  later addition, explicitly out of scope for v1.

## Deployment

- A GitHub Actions workflow (`.github/workflows/generator-pages.yml`) uploads
  the `generator/` directory as the Pages artifact and deploys it.
- Per the repo's supply-chain standards: third-party actions pinned to full
  commit SHAs, `permissions` minimized (`pages: write`, `id-token: write`
  scoped to the deploy job only), no `pull_request_target`.
- The `generator/` tree is fully static; the workflow performs no build.

## Security & supply chain

- One vendored runtime dependency (`fflate`), pinned and provenance-commented;
  no CDN scripts, no external fonts or styles. The page makes no network
  requests at runtime.
- Secrets are generated locally via Web Crypto and never leave the browser.
- The generated `.env` contains real secrets; the README/next-steps warn the
  user to keep it out of version control and treat it as sensitive.

## Open questions

None outstanding. Image-tag default (`v1.1.5`) is a single constant to bump on
future releases and is intentionally not automated in v1.

## Future (explicitly out of scope for v1)

- Additional server images beyond Legendary.
- Kubernetes / Podman / Swarm output.
- Deep-link/shareable URLs that encode a configuration.
- A Playwright UI smoke test.
