# Fixture: Paper + Geyser + Floodgate

Task 20's dedicated fixture for the test matrix's "Paper+Geyser+Floodgate"
Minecraft-filesystem value (`docs/operations/test-matrix.md`). Used
exclusively by `docker-compose.integration.yml`, which copies this
directory's contents into a disposable, shared Docker volume both the
CraftKeeper container and the fake bounded RCON service mount — never
consumed directly by the PHP/Vitest/Playwright test suites (those use
`tests/fixtures/minecraft`, which must stay untouched by anything in this
directory).

- `plugins/Geyser-Spigot.jar` / `plugins/floodgate.jar` — real, minimal,
  valid ZIP archives containing only a `plugin.yml` each (built the same
  programmatic way `tests/fixtures/plugins/JarFixtureBuilder.php` builds
  its own jar fixtures — see that class's docblock for why this project
  prefers reproducible-from-source jars over opaque committed binaries).
  Both are real enough for `App\Plugins\JarInspector`/
  `PluginInventoryService` to discover, inspect, and report on; neither
  contains real Geyser/Floodgate bytecode — the integration stack never
  actually runs a Bedrock-compatible listener.
- `plugins/Geyser-Spigot/config.yml`, `plugins/floodgate/config.yml` —
  each plugin's own already-installed config directory, matching how a
  real Paper server lays these out (the JAR file itself alongside a
  same-named directory the plugin writes its own config into at
  runtime).
- `server.properties` — RCON enabled, matching the fake RCON service's
  own bound port.
- `logs/latest.log` — a few realistic Paper startup lines (including
  Geyser/Floodgate's own boot messages) `App\Server\LogTailService` can
  tail immediately on container start, so the integration stack's "live
  console" scenario has something to show without waiting on a real
  server process to produce output.
