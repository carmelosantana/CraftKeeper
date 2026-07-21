# Installation: alongside Legendary Java Minecraft (Geyser + Floodgate)

This is the **primary supported integration**: running CraftKeeper as a sidecar
to [TheRemote/Legendary-Java-Minecraft-Geyser-Floodgate][legendary], a Paper
server image that bundles Geyser and Floodgate for Bedrock crossplay.

The two containers share one named volume. Legendary runs the server; CraftKeeper
reads and writes its configuration through the same propose → approve → execute
pipeline it uses everywhere else.

[legendary]: https://github.com/TheRemote/Legendary-Java-Minecraft-Geyser-Floodgate

> **Image name.** The upstream README links to Docker Hub as
> `05jchambers/legendary-minecraft-geyser-floodgate`. That is the name used
> throughout this document.

## How the two images fit together

CraftKeeper and Legendary are built independently and pick their own user
accounts, so the shared volume would normally be a permission minefield.
**CraftKeeper resolves this itself at startup — there is nothing to configure.**

On boot its entrypoint reads the ownership of the mounted volume, joins that
group, ensures the directories it must write are group-writable, and then drops
to its own unprivileged user before anything else runs. You will see it in the
container log:

```
[entrypoint] joined group minecraft-host (gid 999) — matches /minecraft (uid 999)
[entrypoint] added group-write to /minecraft (was 755) so atomic writes can land
[entrypoint] dropping to craftkeeper (uid 1000)
```

Why it has to work this way, in case you are auditing it:

- Legendary runs the server as a `minecraft` user created with `useradd -r`.
  That lands on uid/gid 999 today but is **not pinned by the image**, so no
  fixed number in a compose file would be reliable. It also re-`chown`s the
  whole volume on every boot.
- Its files are group-writable (664) and its subdirectories are 775, so joining
  the owning group is enough for CraftKeeper to work — no ownership changes,
  and CraftKeeper never `chown`s the server's files.
- The volume **root**, however, is 755. CraftKeeper writes atomically (temp
  file in the same directory, then `rename`), which needs write permission on
  the directory itself. Without the group-write bit there, edits to `config/`
  and `plugins/` would succeed while `server.properties` — the file holding
  your RCON settings — silently failed. That split is worse than a clean
  failure, so the entrypoint closes it.

The permission adjustment only ever *adds* the group-write bit, only to
directories, and only to directories already owned by a group CraftKeeper has
just joined. It never changes ownership and never touches file contents. Set
`CRAFTKEEPER_ADAPT_PERMISSIONS=off` to disable it and have CraftKeeper report
the condition instead of correcting it.

If you run the container with an explicit `user:` in compose, CraftKeeper sees
it is not root, skips all of the above, and runs exactly as you specified — at
which point supplying the right supplementary group is your responsibility.

## Enabling RCON

RCON drives CraftKeeper's console. Legendary ships it **disabled**, with an
empty password, and does not publish the port:

```
enable-rcon=false
rcon.port=25575
rcon.password=
```

Set it once, in the shared volume:

```bash
docker exec -u 999 <legendary-container> sh -c "
  sed -i 's/^enable-rcon=.*/enable-rcon=true/' /minecraft/server.properties
  sed -i 's/^rcon.password=.*/rcon.password=CHANGE-ME/' /minecraft/server.properties
"
docker restart <legendary-container>
```

Use a strong password — RCON is unauthenticated beyond this single shared
secret, and it grants full server command execution.

**These edits persist across restarts.** Legendary copies its
`server.properties` template only when the file is absent; on later boots it
rewrites just two lines in place (`server-port` and `query.port`, driven by the
`Port` variable) and never touches `enable-rcon`, `rcon.port`, or
`rcon.password`. Verified across a restart.

You do **not** need to publish 25575 to the host. CraftKeeper reaches RCON over
the Docker network, so the port stays private to the compose network — which is
where you want it.

### Connecting the console

CraftKeeper does not take RCON credentials from environment variables. The host
and port are stored as settings and the password goes into its encrypted secret
store, so it is never sitting in a compose file or process environment.

Enter them in the **RCON step of onboarding**, or later under **Settings → RCON**:

| Field | Value |
| --- | --- |
| Host | `minecraft` (the service name on the compose network) |
| Port | `25575` |
| Password | whatever you set in `server.properties` |

Both screens test the connection when you save, so you get a pass/fail there
rather than discovering it later on the Console page.

## Compose file

```yaml
services:
  minecraft:
    image: 05jchambers/legendary-minecraft-geyser-floodgate:latest
    container_name: minecraft
    restart: unless-stopped
    ports:
      - "25565:25565"       # Java
      - "19132:19132/udp"   # Bedrock (Geyser)
    volumes:
      - minecraft:/minecraft
    environment:
      MaxMemory: "4096"
      TZ: America/Denver
    # RCON (25575) is deliberately NOT published — CraftKeeper reaches it
    # over the internal network below.

  craftkeeper:
    image: ghcr.io/carmelosantana/craftkeeper:latest
    container_name: craftkeeper
    restart: unless-stopped
    depends_on:
      - minecraft
    ports:
      - "8080:8080"
    volumes:
      - minecraft:/minecraft          # SAME volume as the server
      - craftkeeper_data:/data
    # No group_add and no chmod: CraftKeeper matches the volume's ownership
    # itself at startup. See "How the two images fit together" above.
    environment:
      APP_URL: http://localhost:8080
      APP_KEY: ${CRAFTKEEPER_APP_KEY}
      MINECRAFT_ROOT: /minecraft
      DATA_ROOT: /data
      DB_CONNECTION: sqlite
      DB_DATABASE: /data/database.sqlite
      QUEUE_CONNECTION: database
      CACHE_STORE: database
      SESSION_DRIVER: database
      BROADCAST_CONNECTION: log
      # RCON is NOT configured here. CraftKeeper stores the host and port as
      # settings and the password in its encrypted secret store, entered
      # through onboarding or Settings. See "Connecting the console" below.
    healthcheck:
      test: ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:8080/up"]
      interval: 30s
      timeout: 5s
      retries: 3

volumes:
  minecraft:
  craftkeeper_data:
```

## Bring-up

```bash
# 1. Generate an app key and choose an RCON password
export CRAFTKEEPER_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export MINECRAFT_RCON_PASSWORD="$(head -c 18 /dev/urandom | base64)"

# 2. Start the Minecraft server first so it seeds /minecraft
docker compose up -d minecraft

# 3. Wait for the volume to be seeded, then turn on RCON. This is the only
#    manual step, and it is server-side configuration rather than anything
#    CraftKeeper needs — Legendary ships RCON disabled with an empty password.
until docker exec minecraft test -f /minecraft/server.properties; do sleep 2; done
docker exec -u "$(docker exec minecraft id -u minecraft)" minecraft sh -c "
  sed -i 's/^enable-rcon=.*/enable-rcon=true/' /minecraft/server.properties
  sed -i \"s/^rcon.password=.*/rcon.password=$MINECRAFT_RCON_PASSWORD/\" /minecraft/server.properties
"
docker restart minecraft

# 4. Start CraftKeeper. It adapts to the volume's ownership on its own;
#    `docker compose logs craftkeeper | grep entrypoint` shows what it did.
docker compose up -d craftkeeper
```

Open <http://localhost:8080> and complete onboarding. At the RCON step enter
host `minecraft`, port `25575`, and the password you set above; it tests the
connection on save. The Configurations page will then list `server.properties`,
`config/paper-global.yml`, and the Geyser plugin config from the shared volume.

## Behavior worth knowing

These are properties of the Legendary image, not defects in either project.
They shape what you should expect to see in CraftKeeper.

**Plugin jars are re-downloaded on every boot.** Paper (`paperclip.jar`), Geyser,
Floodgate, and ViaVersion are fetched fresh each start. Plugin versions in
CraftKeeper's inventory will therefore change on their own after a restart — this
is the server image updating itself, not CraftKeeper mutating anything. Set
`NoViaVersion` to opt out of that one plugin.

**`eula.txt` is rewritten every boot.** Legendary unconditionally writes
`eula=true`. Editing it through CraftKeeper will not stick.

**`plugins/floodgate/config.yml` does not exist until Floodgate has run once.**
The image downloads the Floodgate jar but does not seed a config for it, unlike
Geyser. Expect it to be absent from the Configurations page on a brand-new
volume and to appear after the server has fully started once.

**Config files are seeded only when missing.** `server.properties`,
`bukkit.yml`, `spigot.yml`, `config/paper-global.yml`, and
`plugins/Geyser-Spigot/config.yml` are copied from templates on first boot and
left alone afterward, so your CraftKeeper edits persist. The exceptions are the
narrow per-boot rewrites already described: `server-port` and `query.port` in
`server.properties`, and the Bedrock `port` in the Geyser config.

**Ownership is reclaimed on every server restart.** The recursive `chown` means
any file CraftKeeper creates reverts to `minecraft` ownership when the server
restarts. Harmless: CraftKeeper reaches the volume through the group bit rather
than through ownership, so reclaimed ownership changes nothing.

## Troubleshooting

**Configurations page is empty.** The volume is not shared or is not seeded.
Confirm both services mount the same volume, and that
`docker exec craftkeeper ls /minecraft` lists the server's files.

**Reads work, every save fails.** CraftKeeper did not join the volume's group.
Check `docker compose logs craftkeeper | grep entrypoint` — it should report
which group it joined. If it says it could not, or says nothing at all, the
container is probably running with an explicit `user:` (which disables the
adaptation) or with `CRAFTKEEPER_ADAPT_PERMISSIONS=off`.

**Everything saves except `server.properties`.** The volume root is not
group-writable and the permission adaptation is disabled. Either remove
`CRAFTKEEPER_ADAPT_PERMISSIONS=off`, or apply it yourself once with
`docker exec -u 0 minecraft chmod g+w /minecraft`.

**Console shows "Unavailable".** RCON is not reachable. Check `enable-rcon=true`
in `server.properties`, that the server was restarted after the edit, that
`RCON_PASSWORD` matches, and that both containers are on the same compose
network. CraftKeeper reports the reason rather than a fabricated status — read
what it says.
