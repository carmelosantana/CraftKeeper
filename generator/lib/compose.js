import { IMAGES } from './services.js';

function craftkeeper(s) {
  const lines = [
    '  craftkeeper:',
    `    image: ${IMAGES.craftkeeper}`,
    '    container_name: craftkeeper',
    '    restart: unless-stopped',
    '    depends_on:',
    '      - minecraft',
  ];
  if (s.publishCraftkeeperPort) {
    lines.push('    ports:', `      - "${s.craftkeeperPort}:8080"`);
  }
  lines.push(
    '    volumes:',
    '      - minecraft:/minecraft',
    '      - craftkeeper_data:/data',
    '    environment:',
    '      APP_URL: ${CRAFTKEEPER_APP_URL}',
    '      APP_KEY: ${CRAFTKEEPER_APP_KEY}',
    '      MINECRAFT_ROOT: /minecraft',
    '      DATA_ROOT: /data',
    '      DB_CONNECTION: sqlite',
    '      DB_DATABASE: /data/database.sqlite',
    '      QUEUE_CONNECTION: database',
    '      CACHE_STORE: database',
    '      SESSION_DRIVER: database',
  );
  if (s.realtime) {
    lines.push(
      '      BROADCAST_CONNECTION: reverb',
      '      REVERB_APP_ID: ${REVERB_APP_ID}',
      '      REVERB_APP_KEY: ${REVERB_APP_KEY}',
      '      REVERB_APP_SECRET: ${REVERB_APP_SECRET}',
    );
  } else {
    lines.push('      BROADCAST_CONNECTION: log');
  }
  if (s.trustedProxies) lines.push(`      TRUSTED_PROXIES: "${s.trustedProxies}"`);
  if (s.trustedHosts) lines.push(`      TRUSTED_HOSTS: "${s.trustedHosts}"`);
  lines.push(
    '    healthcheck:',
    '      test: ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:8080/up"]',
    '      interval: 30s',
    '      timeout: 5s',
    '      retries: 3',
  );
  return lines;
}

function pluginUpdater() {
  return [
    '  plugin-updater:',
    `    image: ${IMAGES.pluginUpdater}`,
    '    pull_policy: always',
    '    restart: "no"',
    '    volumes:',
    '      - minecraft:/minecraft',
  ];
}

function minecraft(s) {
  const lines = [
    '  minecraft:',
    `    image: ${IMAGES.minecraft}`,
    '    container_name: minecraft',
    '    restart: unless-stopped',
  ];
  if (s.pluginUpdater) {
    lines.push(
      '    depends_on:',
      '      plugin-updater:',
      '        condition: service_completed_successfully',
    );
  }
  lines.push(
    '    ports:',
    `      - "${s.javaPort}:25565"`,
    `      - "${s.bedrockPort}:19132"`,
    `      - "${s.bedrockPort}:19132/udp"`,
    '    volumes:',
    '      - minecraft:/minecraft',
    '    stdin_open: true',
    '    tty: true',
    '    entrypoint: ["/bin/bash", "/scripts/start.sh"]',
    '    environment:',
    '      Port: "25565"',
    '      BedrockPort: "19132"',
    `      TZ: ${s.timezone}`,
    `      MaxMemory: ${s.memory}`,
    '      QuietCurl: "Y"',
  );
  if (s.minecraftVersion) lines.push(`      Version: "${s.minecraftVersion}"`);
  if (s.resourceLimits) {
    lines.push(
      '    deploy:',
      '      resources:',
      '        limits:',
      `          cpus: "${s.cpus}"`,
      `          memory: "${s.memoryLimit}"`,
      '        reservations:',
      `          cpus: "${s.cpus}"`,
      `          memory: "${s.memoryLimit}"`,
    );
  }
  return lines;
}

function fileBrowser(s) {
  return [
    '  filebrowser:',
    `    image: ${IMAGES.fileBrowser}`,
    '    restart: unless-stopped',
    '    ports:',
    `      - "${s.fileBrowserPort}:80"`,
    '    volumes:',
    '      - minecraft:/srv/minecraft',
    '      - filebrowser_config:/config',
    '      - filebrowser_database:/database',
    '    environment:',
    `      PUID: "${s.puid}"`,
    `      PGID: "${s.pgid}"`,
    `      TZ: ${s.timezone}`,
    '    command: --config /config/filebrowser.json --database /database/filebrowser.db --root /srv',
  ];
}

export function buildCompose(s) {
  const services = [craftkeeper(s)];
  if (s.pluginUpdater) services.push(pluginUpdater());
  services.push(minecraft(s));
  if (s.fileBrowser) services.push(fileBrowser(s));

  const volumes = ['  minecraft:', '  craftkeeper_data:'];
  if (s.fileBrowser) volumes.push('  filebrowser_config:', '  filebrowser_database:');

  const servicesText = services.map((block) => block.join('\n')).join('\n\n');
  return `services:\n${servicesText}\n\nvolumes:\n${volumes.join('\n')}\n`;
}
