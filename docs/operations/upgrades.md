# Upgrades, migrations, and rollback

## Upgrading

CraftKeeper images are published to GHCR on every signed `v*` tag as
four tags pointing at the same multi-architecture manifest digest:

```
ghcr.io/carmelosantana/craftkeeper:v1.2.3   # exact version — immutable in practice (a given X.Y.Z is only ever published once)
ghcr.io/carmelosantana/craftkeeper:v1.2     # latest patch within the 1.2 minor line — moves forward
ghcr.io/carmelosantana/craftkeeper:v1       # latest minor+patch within the 1.x major line — moves forward
ghcr.io/carmelosantana/craftkeeper:latest   # latest final (non-prerelease) release overall — moves forward
```

`:v1.2.3`-style tags are the ones to reference in production if you want
explicit control over exactly when you move to a new version;
`:v1`/`:latest` are conveniences that track forward automatically as new
compatible releases ship. `:latest` is **never** published for a
prerelease tag (e.g. `v1.3.0-rc.1`) — `.github/workflows/image.yml`'s tag
computation step refuses to include it in that case, so `:latest` always
points at the newest stable release, never a release candidate.

To upgrade:

```bash
docker compose -f compose.example.yml pull
docker compose -f compose.example.yml up -d
```

`docker/entrypoint.sh` runs `php artisan migrate --force` on every
container boot, against whatever is already in `{DATA_ROOT}/
database.sqlite` — this is how schema migrations for a new version are
applied. Laravel's migration runner only ever applies migrations that
haven't already run (tracked in the `migrations` table), so upgrading
repeatedly, or restarting without a version change at all, is always
safe to re-run.

**Before upgrading across a minor or major version**, read that
version's `CHANGELOG.md` entry and the corresponding GitHub Release
notes for anything called out as a breaking change or a required manual
step. **Take a backup first** (`docs/operations/recovery.md`) — a
migration is not itself reversible by CraftKeeper; the safety net for a
bad upgrade is a backup taken before it, or the digest-based rollback
below (which only helps if the new version's migrations haven't yet
changed data in a way the old version can't read — see the warning
below).

## Rollback by image digest

A moving tag (`:v1`, `:latest`) is a pointer that can point at a
different image tomorrow than it does today — it is **not** what you
want to reference if you need to pin to, or roll back to, one specific,
verifiable build. The image **digest** (`sha256:...`) is immutable by
construction: it is a content hash of the manifest, and pulling the same
digest twice always gets you the exact same bytes.

Find the digest of the version you're currently running (or of any
previously published tag) via:

```bash
docker buildx imagetools inspect ghcr.io/carmelosantana/craftkeeper:v1.2.3
# or, for an image you already have locally:
docker inspect --format='{{index .RepoDigests 0}}' ghcr.io/carmelosantana/craftkeeper:v1.2.3
```

To pin (or roll back to) that exact build, reference the digest directly
in place of a tag in `compose.example.yml`:

```yaml
services:
  craftkeeper:
    image: ghcr.io/carmelosantana/craftkeeper@sha256:<digest>
```

then:

```bash
docker compose -f compose.example.yml pull
docker compose -f compose.example.yml up -d
```

**Rollback caveat:** rolling the *image* back does not roll the
*database schema* back — if the version you're rolling back from already
ran a forward migration that the older version's code doesn't know how
to read, rolling back the container alone is not sufficient. This is why
"take a backup first" above matters: a true rollback, if the schema has
moved forward, means restoring the pre-upgrade backup's
`database.sqlite` (see `docs/operations/recovery.md`) alongside pinning
the older image digest — not just changing the image reference.

## Verifying what you're running

Every published manifest is signed with keyless Sigstore/Cosign and
carries SPDX + CycloneDX SBOM attestations and SLSA build provenance.
Before rolling forward or back, you can verify a specific tag or digest
actually came from this project's own CI (not a re-tagged or tampered
image):

```bash
cosign verify --certificate-identity-regexp 'https://github.com/carmelosantana/craftkeeper/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/carmelosantana/craftkeeper:v1.2.3

# Inspect the attached SBOM/provenance attestations:
cosign verify-attestation --type spdxjson \
  --certificate-identity-regexp 'https://github.com/carmelosantana/craftkeeper/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/carmelosantana/craftkeeper:v1.2.3
```

## Troubleshooting permissions after an upgrade

If a new image version changes anything about the non-root
`craftkeeper` user (it has been uid/gid `1000` since Task 2 and is not
expected to change), a previously-working `/minecraft` mount could
suddenly show permission-denied discovery errors again after an
upgrade. Re-run the write-access check from
`docs/installation/docker-compose.md`'s "File UID/GID strategy" section
to confirm; `/data` itself is always created and owned correctly by the
entrypoint on first boot and does not need this check.
