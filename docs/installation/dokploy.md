# Installing CraftKeeper behind Dokploy

This is a Dokploy-specific supplement to
[`docs/installation/docker-compose.md`](docker-compose.md) — read that
document first for the shared-volume, UID/GID, and `APP_KEY`
requirements, which apply identically here. This page only covers what
is specific to running CraftKeeper as a Dokploy-managed application:
proxy/HTTPS/websocket configuration and trusted-proxy settings.

CraftKeeper's deployment target **is** "Docker Compose behind Dokploy
HTTPS, application container exposing HTTP on port 8080" — this is not
an unusual or unsupported path.

## 1. Create the application

In Dokploy, create a new application from `compose.example.yml` (Compose
provider) or point a Docker-image-based application at
`ghcr.io/carmelosantana/craftkeeper:v1` directly and reproduce that
file's `environment`/`volumes`/`healthcheck` sections in Dokploy's own
service editor. Either way, the container must expose port `8080` — do
not remap it internally; Dokploy's Traefik-based proxy connects to the
container's published/internal port, not a host port you choose.

## 2. Proxy and domain settings

- Add a domain in Dokploy's "Domains" tab pointing at this application,
  container port **`8080`**.
- Enable **HTTPS** (Dokploy provisions/renews the certificate via
  Traefik + Let's Encrypt automatically once the domain's DNS resolves
  to your Dokploy host). CraftKeeper itself never terminates TLS — it
  only ever speaks plain HTTP on `8080`, expecting Dokploy's proxy to be
  the HTTPS boundary.
- Set `APP_URL` to the **`https://`** URL you configured in Dokploy, not
  `http://` — several parts of the application (CSRF-protected form
  submissions, the CSP's own origin checks, Reverb's client-side
  connection URL derived from `VITE_REVERB_*`) assume `APP_URL` matches
  what the browser actually connects to.

## 3. WebSocket passthrough (Laravel Reverb)

CraftKeeper's own Nginx (inside the container, `docker/nginx/
default.conf`) already proxies websocket upgrade requests at `/app` to
Reverb on `127.0.0.1:8081` — Dokploy's Traefik proxy does not need a
second, separate route for `/app`; it only needs to forward the single
container port (`8080`) for **both** normal HTTP and the WebSocket
upgrade on the same host/path rule, which is Traefik's default behavior
for a standard HTTP router (the `Upgrade`/`Connection` headers pass
through unmodified). Concretely:

- Do **not** create a second Dokploy domain/route pointing only at
  `/app` — one route for the whole application, forwarded to `:8080`, is
  correct and sufficient.
- If Dokploy's UI exposes a "WebSocket support" toggle for the domain,
  enable it. If it does not (recent Traefik versions handle this
  transparently for standard HTTP routers with no explicit toggle),
  no action is needed.
- If you see the browser console repeatedly report a Reverb/Echo
  reconnect loop, check first whether any configured proxy **idle/read
  timeout** is shorter than Reverb's own ping interval — a timeout
  around a minute (matching Nginx's own `proxy_read_timeout 60s` on the
  `/app` location) is a safe floor; CraftKeeper's own Echo client already
  reconnects automatically on a dropped socket, so a short timeout
  degrades to visible-but-recoverable reconnects rather than a stuck UI.

## 4. Trusted proxies

Dokploy's Traefik proxy sits in front of the container and terminates
TLS — from the container's own point of view, every request arrives
over plain HTTP from Traefik's internal address, not directly from the
browser. Unless CraftKeeper is told to trust that hop, `$request->
isSecure()` never reflects the real (HTTPS) scheme the browser used,
which silently keeps the `Strict-Transport-Security` header and
Laravel's secure-cookie auto-detection turned off even though the
connection really is HTTPS end-to-end.

Set the `TRUSTED_PROXIES` environment variable on the CraftKeeper service
in Dokploy:

```
TRUSTED_PROXIES=*
```

This is safe specifically **because** Dokploy's own network model
already ensures the CraftKeeper container is never directly reachable
from outside — only Traefik is exposed on the host's public ports, and
the application container sits on an internal Docker network Traefik
proxies into. If you deploy CraftKeeper any other way where the
container itself might be reachable on a public interface without going
through a proxy, use the proxy's specific IP/CIDR here instead of `*`
(see `.env.example`'s own comment on this variable, and
`docs/architecture/decisions.md`, Task 20, for the full rationale).

## 5. Shared external volume in Dokploy

Dokploy's volume picker lets you attach an existing named Docker volume
to a service (rather than only letting Dokploy create a new one for
you) — select the **same** volume your Minecraft server application (if
it's also Dokploy-managed) already uses, mounted at `/minecraft` on the
CraftKeeper service. See
[`docs/installation/docker-compose.md`](docker-compose.md)'s "Choose the
shared external volume" and "File UID/GID strategy" sections for the
ownership requirements — those apply identically whether the volume was
created by Dokploy's UI or a plain `docker volume create`.

Give CraftKeeper's own state (`craftkeeper_data`, mounted at `/data`) a
**separate** volume from `/minecraft` — Dokploy's own automatic volume
naming per-service already keeps these distinct by default; just don't
manually point both mounts at the same volume.

## 6. Health check

Dokploy reads the image's own `HEALTHCHECK` (`GET /up` every 30s) for
its service-health indicator — no additional health-check configuration
is required in Dokploy's UI beyond leaving the default "use the
container's healthcheck" behavior enabled.
