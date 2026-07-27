// Pinned image references. Bump the craftkeeper tag here on each release.
export const IMAGES = {
  craftkeeper: 'ghcr.io/carmelosantana/craftkeeper:v1.1.5',
  minecraft: '05jchambers/legendary-minecraft-geyser-floodgate:latest',
  pluginUpdater: 'ghcr.io/carmelosantana/minecraft-plugin-updater:latest',
  fileBrowser: 'filebrowser/filebrowser:latest',
};

// Default configuration. Every form field reads its initial value from here.
export const DEFAULTS = {
  // Core
  domain: 'http://localhost:8080',
  timezone: 'America/New_York',
  memory: 4096,
  pluginUpdater: true,
  fileBrowser: false,
  // Advanced — craftkeeper
  publishCraftkeeperPort: true,
  craftkeeperPort: 8080,
  trustedProxies: '',
  trustedHosts: '',
  realtime: false,
  // Advanced — minecraft
  javaPort: 25565,
  bedrockPort: 19132,
  minecraftVersion: '',
  resourceLimits: false,
  cpus: 8,
  memoryLimit: '16g',
  // Advanced — filebrowser
  fileBrowserPort: 8081,
  puid: 999,
  pgid: 999,
};
