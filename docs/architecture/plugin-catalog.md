# The CraftKeeper Plugin Catalog: a handoff to an independent repository

This document specifies the boundary between CraftKeeper (this repository)
and `carmelosantana/minecraft-plugin-catalog` (an independent repository
that does not exist inside this codebase and is not built by this task).
It exists so that repository's maintainers — and CraftKeeper's own future
contributors — have one place that states the contract both sides
actually rely on.

## The contract is the schema file, not this document

The full, authoritative contract is
[`resources/catalog/plugin-catalog.schema.json`](../../resources/catalog/plugin-catalog.schema.json)
— a JSON Schema (draft-07) checked into *this* repository. Both sides of
the handoff read the exact same file:

- **The catalog repository** validates every catalog document it
  publishes against this schema **in its own CI**, before merge and
  before publishing a release of the catalog. A pull request that adds
  or edits a plugin entry that does not validate must not be mergeable.
  (The catalog repository is expected to vendor or fetch a copy of this
  schema file as part of its own CI setup — see "Keeping the schema in
  sync" below.)
- **CraftKeeper** (`App\Catalog\Sources\CraftKeeperCatalogSource`) fetches
  the published document over HTTP and normalizes it into the same
  `App\Catalog\Data\PluginRelease` DTO every other catalog source
  (Hangar, Modrinth) also produces. `App\Catalog\CatalogSchemaValidator`
  — which wraps `justinrainbow/json-schema` around this exact file — is
  the single reusable validator both `PluginCatalogContractTest` (proving
  the schema itself accepts/rejects each fixture category) and, if
  wanted, the catalog repository's own tooling can call.

**The existing plugin updater and CraftKeeper are two independent
consumers of one producer.** Server owners who currently update plugins
by hand, by a shell script, or by any other existing tool that reads the
same published catalog document are consuming the identical JSON — this
schema is not something CraftKeeper invented for itself and privately
owns. Nothing about this contract is CraftKeeper-specific: it describes a
plugin catalog, not a CraftKeeper feature.

## What the schema requires

At the top level: a `catalogVersion` (semver string, versioning the
*contract*, independent of any plugin's own version) and a `plugins`
array. Each plugin requires `slug` (the stable identity — see
"Identity and merging" below), `name`, `description`, `projectUrl`,
`license`, `sourceRepository`, and a non-empty `releases` array. Each
release requires `version`, `minecraftVersions`, `platforms`,
`dependencies` (may be empty), `downloadUrl`, `sha256`, and
`releasedAt`. `withdrawn` (boolean) and `signature` (an object requiring
`algorithm`/`signature`/`keyUrl` once present at all) are optional.
`additionalProperties` is left permissive throughout so the catalog
repository can add new optional fields in a later contract version
without breaking a CraftKeeper install still pinned to an older
`catalogVersion`.

## Releases are immutable by checksum

Once a `(slug, version)` pair is published with a given `sha256`, that
`sha256` MUST NOT change. If a release needs to be corrected, the
catalog repository publishes a **new** release entry (a new `version`,
or a new `sha256` under an explicitly withdrawn old one) rather than
silently swapping the bytes behind an existing version string. This is
what makes a `sha256` a safe, permanent identity for a specific
artifact — CraftKeeper (Task 15's install/download flow) is expected to
verify a downloaded artifact against the `sha256` this contract carries,
and that verification is only meaningful if the catalog itself never
mutates a published hash after the fact.

**Withdrawal, not deletion.** A release the catalog maintainers pull
(e.g. for a security issue) is marked `withdrawn: true`, not removed
from the document. `App\Catalog\Sources\CraftKeeperCatalogSource`
excludes withdrawn releases from active search results (and from an
unqualified "give me the latest" lookup) but can still resolve one by
its exact `(slug, version)` identity — so a server that already has a
since-withdrawn version installed can still be told what it is running
and why it was pulled, rather than that information silently
disappearing. See that class's docblock and
`tests/Feature/Catalog/CraftKeeperCatalogSourceTest.php`.

## CraftKeeper stays functional when the catalog is unavailable

This is a hard requirement, not best-effort:

- Every catalog source (`App\Catalog\PluginSource::search()`) **never
  throws**. A transport failure — timeout, a 5xx that survives two
  retries, an oversized response, a malformed body — is caught inside
  `App\Catalog\Sources\AbstractPluginSource` and turned into a labeled,
  *degraded* `App\Catalog\Data\PluginSourceResult` carried alongside
  whatever items are still available, never a fatal error that takes
  down `App\Catalog\UnifiedCatalogService::search()` as a whole.
- The last successfully fetched CraftKeeper Catalog document is retained
  for **7 days** (`config('catalog.cache.retention_days')`) specifically
  so a catalog outage — the independent repository's hosting is down, a
  network partition, anything — degrades to "slightly stale data,
  clearly labeled as such" rather than "no catalog at all." The same
  retention mechanism (`App\Catalog\CatalogCache`) is applied uniformly
  to Hangar and Modrinth's per-query pages too, for the same reason.
- None of CraftKeeper's other functionality — the plugin inventory
  (Task 13), compatibility assessment, RCON/console/config management —
  depends on the catalog being reachable. The catalog only ever adds
  *discoverability* of new/updatable plugins; it is never load-bearing
  for anything already installed.

## Identity and merging

A catalog plugin's `slug` (not its display `name`) is the project
identity half of `App\Catalog\Data\PluginReleaseId` — `(source:
"Catalog", projectId: slug)`. `App\Catalog\UnifiedCatalogService` merges
results from CraftKeeper Catalog, Hangar, and Modrinth by this exact
`(source, projectId)` pair, never by name: two different plugins can
share a display name, and the same real-world plugin published under
all three sources deliberately appears as three separate, clearly
source-badged results rather than being collapsed into one. See
`UnifiedCatalogService`'s docblock for the full merge/sort contract.

## Keeping the schema in sync

`resources/catalog/plugin-catalog.schema.json` is versioned by
`catalogVersion`, not by this repository's own git history. A breaking
change to the contract (removing a required field, narrowing a type)
must bump the major component of `catalogVersion` and be coordinated
with the catalog repository's own CI update — CraftKeeper does not
auto-discover a schema change, and an old CraftKeeper install reading a
newer catalog document is expected to keep working as long as the
fields it actually reads (see `App\Catalog\Sources\
CraftKeeperReleaseNormalizer`) are still present, per the permissive
`additionalProperties` policy above.
