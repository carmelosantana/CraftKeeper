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

## Read this first: two things you must configure

Legendary and CraftKeeper are independently built images that were never
designed against each other. Two mismatches must be resolved or the integration
silently half-works — CraftKeeper will show your configuration correctly but
fail to save any change.

Both are one-time fixes and both are in the compose file below.

### 1. UID mismatch — CraftKeeper cannot write without a supplementary group

Legendary runs its server as a `minecraft` user created with `useradd -r`, and
recursively `chown`s the whole data directory to it on **every boot**.
CraftKeeper runs as a fixed `craftkeeper` user, **UID 1000**.

Measured on `05jchambers/legendary-minecraft-geyser-floodgate:latest`:

```
minecraft user:  uid=999(minecraft) gid=999(minecraft)
seeded files:    -rw-rw-r--  (664)  999:999
seeded subdirs:  drwxrwxr-x  (775)  999:999
```

Files are group-writable, so granting CraftKeeper the `999` **supplementary
group** is sufficient — no ownership changes, no running as root:

```yaml
group_add:
  - "999"
```

> **Verify the GID rather than trusting `999`.** `useradd -r` picks the next
> free system ID at container start; it is not pinned by the image. It lands on
> `999` on the current image, but a different base image or an image rebuild can
> shift it. Check yours and use what it reports:
>
> ```bash
> docker exec <legendary-container> id -g minecraft
> ```

Without this, CraftKeeper reads every file fine (they are world-readable) and
fails every write with permission denied.

### 2. The volume root is `755` — `server.properties` needs `chmod g+w`

The supplementary group is necessary but **not sufficient**. Legendary creates
its subdirectories `775` but leaves the data directory root `755`:

```
755  /minecraft            <-- not group-writable
775  /minecraft/config
775  /minecraft/plugins
```

CraftKeeper writes atomically — new content to a temp file in the *same*
directory, then `rename()`. Creating that temp file in a `755` directory fails.
The practical effect is precise and easy to misread:

| Target | Directory mode | Atomic write |
| --- | --- | --- |
| `/minecraft/config/paper-global.yml` | `775` | works |
| `/minecraft/plugins/Geyser-Spigot/config.yml` | `775` | works |
| **`/minecraft/server.properties`** | `755` (root) | **fails** |

That is the worst possible split: `server.properties` is where RCON is enabled
and where most server settings live. Plugin and Paper edits appear to work,
which makes the failure look like a bug in one file rather than a permission
issue.

Fix it once:

```bash
docker exec -u 0 <legendary-container> chmod 775 /minecraft
```

**This persists.** Legendary's per-boot `chown -R` changes ownership only —
POSIX `chown` does not reset permission bits — so the mode survives restarts.
Verified across a container restart.

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
    # Grants write access to Legendary's group-writable files.
    # Confirm the GID first: docker exec minecraft id -g minecraft
    group_add:
      - "999"
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

# 3. Wait for server.properties, then apply the two one-time fixes
until docker exec minecraft test -f /minecraft/server.properties; do sleep 2; done
docker exec -u 0 minecraft chmod 775 /minecraft
docker exec -u 999 minecraft sh -c "
  sed -i 's/^enable-rcon=.*/enable-rcon=true/' /minecraft/server.properties
  sed -i \"s/^rcon.password=.*/rcon.password=$MINECRAFT_RCON_PASSWORD/\" /minecraft/server.properties
"
docker restart minecraft

# 4. Confirm the GID matches the compose file's group_add
docker exec minecraft id -g minecraft

# 5. Start CraftKeeper
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
restarts. Harmless with the group-based setup above, since access comes from the
group bit rather than ownership.

## Troubleshooting

**Configurations page is empty.** The volume is not shared or is not seeded.
Confirm both services mount the same volume, and that
`docker exec craftkeeper ls /minecraft` lists the server's files.

**Reads work, every save fails.** This is the UID mismatch. Confirm the GID with
`docker exec minecraft id -g minecraft` and make sure `group_add` matches it.

**Everything saves except `server.properties`.** The `chmod 775 /minecraft` step
was skipped, or the volume was recreated after it was applied. Re-apply it.

**Console shows "Unavailable".** RCON is not reachable. Check `enable-rcon=true`
in `server.properties`, that the server was restarted after the edit, that
`RCON_PASSWORD` matches, and that both containers are on the same compose
network. CraftKeeper reports the reason rather than a fabricated status — read
what it says.
