# CraftKeeper

The open-source Minecraft server control plane.

CraftKeeper is a Laravel 13 + Inertia 3 / React 19 application that turns a
mounted Minecraft server directory into a safe configuration, plugin, RCON,
AI, REST API, and MCP management experience. It manages exactly one
Minecraft server through a mounted `/minecraft` directory and RCON, and
never requires Docker socket access.

## Quickstart (Docker Compose)

Requires Docker and Compose v2, and an existing named volume (or bind
mount) containing your Minecraft server's files.

```bash
# Generate an application key (32 random bytes, base64-encoded)
export CRAFTKEEPER_APP_KEY="base64:$(openssl rand -base64 32)"

curl -O https://raw.githubusercontent.com/carmelosantana/craftkeeper/main/compose.example.yml
docker compose -f compose.example.yml up -d
```

Then open `http://localhost:8080` (or your configured `APP_URL`) to
complete first-run onboarding — it creates exactly one administrator
account, checks the mounted Minecraft directory, and optionally
configures RCON, an AI provider, and analytics. See
[`docs/installation/docker-compose.md`](docs/installation/docker-compose.md)
for the full walkthrough, or
[`docs/installation/dokploy.md`](docs/installation/dokploy.md) if you're
deploying behind [Dokploy](https://dokploy.com/).

## Documentation

- [Installation: Dokploy](docs/installation/dokploy.md)
- [Installation: Docker Compose](docs/installation/docker-compose.md)
- [Operations: RCON configuration](docs/operations/rcon.md)
- [Operations: Backups, restore, and support bundles](docs/operations/recovery.md)
- [Operations: Upgrades, migrations, and rollback](docs/operations/upgrades.md)
- [Operations: API tokens and MCP OAuth grants](docs/operations/api-and-mcp.md)
- [Operations: Optional AI provider and analytics setup](docs/operations/ai-and-analytics.md)
- [Operations: Test/coverage matrix](docs/operations/test-matrix.md)
- [Operations: V1 acceptance record](docs/operations/v1-acceptance.md)
- [Security: threat model](docs/security/threat-model.md)
- [Architecture: decisions log](docs/architecture/decisions.md)
- [Security policy / vulnerability reporting](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)
- REST API: `openapi.yaml` (also served from the running application's
  Integrations > API page)

## License

CraftKeeper is licensed under the [GNU Affero General Public License v3.0
or later](LICENSE) (`AGPL-3.0-or-later`). The source is free; paid and
donor support may be offered separately (see `SECURITY.md` for how to
report a vulnerability rather than a support request).

## Container image

Signed, multi-architecture (`linux/amd64` + `linux/arm64`) images are
published to GHCR on every tagged release:

```
ghcr.io/carmelosantana/craftkeeper:latest
ghcr.io/carmelosantana/craftkeeper:v1
ghcr.io/carmelosantana/craftkeeper:v1.0
ghcr.io/carmelosantana/craftkeeper:v1.0.0
```

Every published manifest is signed with keyless Sigstore/Cosign and
carries SLSA build provenance plus SPDX and CycloneDX SBOM attestations.
Verify before deploying:

```bash
cosign verify --certificate-identity-regexp 'https://github.com/carmelosantana/craftkeeper/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/carmelosantana/craftkeeper:v1
```

## Development

Requires PHP 8.4, Node 22+, and Composer 2.

```bash
composer install
npm install
composer setup    # .env, APP_KEY, Passport keys, migrate, build assets
```

### Verification gates

```bash
composer test     # config cache clear, Pest test suite, PHPStan, Pint (check-only)
npm run test      # Vitest unit tests
npm run typecheck # TypeScript type checking
npm run build     # Vite production build
npm run e2e       # Playwright end-to-end suite
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the full contribution
workflow, non-negotiable architectural boundaries, and CI/CD change
requirements.
