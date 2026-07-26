# Compose Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A self-contained, zero-build static web tool at `generator/` that assembles a complete, valid Docker Compose stack for CraftKeeper (+ Legendary Minecraft, optional plugin-updater, optional File Browser) and hands the user a downloadable bundle.

**Architecture:** Pure, DOM-free builder modules under `generator/lib/` turn one plain config object into four text artifacts (`compose.yml`, `.env`, `README.md`, next-steps). A thin `main.js` binds a static HTML form to that object and re-renders a live preview. Everything runs by opening `index.html` — no transpile, no bundler.

**Tech Stack:** Plain JavaScript ES modules, Web Crypto for secrets, one vendored zip library (`fflate`), `node --test` for unit tests, GitHub Pages for hosting.

## Global Constraints

- **Zero build, zero `npm install`.** The only npm-style file is `generator/package.json` = `{"type":"module","private":true}` (needed so Node and browsers treat `.js` as ES modules). No dependencies are installed; `fflate` is vendored.
- **All work lives under `generator/`** plus one file under `.github/workflows/`. Do NOT touch the Laravel app, `resources/js`, `vite.config.*`, or the app's test config.
- **ES modules only** (`import`/`export`). Tests run with `node --test` from inside `generator/` (Node 22+).
- **Image tags are pinned constants.** CraftKeeper tag = `ghcr.io/carmelosantana/craftkeeper:v1.1.5` (single constant in `services.js`).
- **`compose.yml` must be deterministic and secret-free.** It references secrets via `${VAR}` interpolation; the actual secret values live only in `.env`. Secrets are generated client-side via `crypto.getRandomValues`.
- **The top-level `volumes:` block is derived from enabled services**, never hand-authored — this is the whole point (a mounted-but-undeclared volume breaks `docker compose up`).
- **Supply chain:** vendored `fflate` pinned to an exact version with a provenance/license header comment. The Pages workflow pins every third-party action to a full commit SHA, uses minimal `permissions`, and never uses `pull_request_target`.
- **Git identity:** `Carmelo Santana <me@carmelosantana.com>` (already the repo default).
- Work happens on branch `feature/compose-generator` (already checked out; the spec commits are already on it).

---

### Task 1: Scaffold, service constants, and default state

**Files:**
- Create: `generator/package.json`
- Create: `generator/lib/services.js`
- Create: `generator/lib/state.js`
- Test: `generator/tests/services.test.js`

**Interfaces:**
- Produces: `IMAGES` (object of pinned image refs), `DEFAULTS` (object of default config values), `defaultState()` → a fresh config object `{ ...DEFAULTS, secrets: null }`.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/services.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { IMAGES, DEFAULTS } from '../lib/services.js';
import { defaultState } from '../lib/state.js';

test('craftkeeper image is pinned to an explicit version, not :latest', () => {
  assert.match(IMAGES.craftkeeper, /^ghcr\.io\/carmelosantana\/craftkeeper:v\d+\.\d+\.\d+$/);
});

test('DEFAULTS has the expected core values', () => {
  assert.equal(DEFAULTS.domain, 'http://localhost:8080');
  assert.equal(DEFAULTS.timezone, 'America/New_York');
  assert.equal(DEFAULTS.memory, 4096);
  assert.equal(DEFAULTS.pluginUpdater, true);
  assert.equal(DEFAULTS.fileBrowser, false);
  assert.equal(DEFAULTS.publishCraftkeeperPort, true);
  assert.equal(DEFAULTS.realtime, false);
});

test('defaultState is a fresh copy with a secrets slot', () => {
  const a = defaultState();
  const b = defaultState();
  assert.notEqual(a, b);
  a.domain = 'changed';
  assert.equal(b.domain, 'http://localhost:8080');
  assert.equal(a.secrets, null);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/services.test.js`
Expected: FAIL — cannot find module `../lib/services.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/package.json`:

```json
{
  "name": "craftkeeper-compose-generator",
  "version": "0.0.0",
  "private": true,
  "type": "module",
  "scripts": {
    "test": "node --test"
  }
}
```

Create `generator/lib/services.js`:

```js
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
```

Create `generator/lib/state.js`:

```js
import { DEFAULTS } from './services.js';

// A fresh, mutable config object. `secrets` is filled in by the UI (or a test)
// with the output of generateSecrets().
export function defaultState() {
  return { ...DEFAULTS, secrets: null };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd generator && node --test tests/services.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add generator/package.json generator/lib/services.js generator/lib/state.js generator/tests/services.test.js
git commit -m "feat(generator): service constants and default state"
```

---

### Task 2: Secret generation

**Files:**
- Create: `generator/lib/secrets.js`
- Test: `generator/tests/secrets.test.js`

**Interfaces:**
- Produces: `generateSecrets()` → `{ appKey, rconPassword, reverbAppId, reverbAppKey, reverbAppSecret }`. `appKey` is `"base64:" + <32 random bytes base64>`; the token fields are URL-safe base64 strings; `reverbAppId` is a 6-digit numeric string.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/secrets.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateSecrets } from '../lib/secrets.js';

test('appKey is base64: prefixed and decodes to 32 bytes', () => {
  const { appKey } = generateSecrets();
  assert.ok(appKey.startsWith('base64:'));
  const raw = atob(appKey.slice('base64:'.length));
  assert.equal(raw.length, 32);
});

test('rconPassword is a non-empty URL-safe token', () => {
  const { rconPassword } = generateSecrets();
  assert.ok(rconPassword.length >= 16);
  assert.match(rconPassword, /^[A-Za-z0-9_-]+$/);
});

test('reverbAppId is a 6-digit string; reverb tokens are URL-safe', () => {
  const s = generateSecrets();
  assert.match(s.reverbAppId, /^\d{6}$/);
  assert.match(s.reverbAppKey, /^[A-Za-z0-9_-]+$/);
  assert.match(s.reverbAppSecret, /^[A-Za-z0-9_-]+$/);
});

test('two calls produce different secrets', () => {
  assert.notEqual(generateSecrets().appKey, generateSecrets().appKey);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/secrets.test.js`
Expected: FAIL — cannot find module `../lib/secrets.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/lib/secrets.js`:

```js
// Web Crypto is a global in both modern browsers and Node 22+ (globalThis.crypto).
// btoa is likewise global in both.
function randomBytes(n) {
  const bytes = new Uint8Array(n);
  crypto.getRandomValues(bytes);
  return bytes;
}

function bytesToBase64(bytes) {
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary);
}

function base64url(bytes) {
  return bytesToBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function generateAppKey() {
  return 'base64:' + bytesToBase64(randomBytes(32));
}

export function generateToken(nBytes = 18) {
  return base64url(randomBytes(nBytes));
}

export function generateReverbId() {
  const b = randomBytes(4);
  const n = ((b[0] | (b[1] << 8) | (b[2] << 16)) % 900000) + 100000;
  return String(n);
}

export function generateSecrets() {
  return {
    appKey: generateAppKey(),
    rconPassword: generateToken(18),
    reverbAppId: generateReverbId(),
    reverbAppKey: generateToken(16),
    reverbAppSecret: generateToken(24),
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd generator && node --test tests/secrets.test.js`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add generator/lib/secrets.js generator/tests/secrets.test.js
git commit -m "feat(generator): client-side secret generation via Web Crypto"
```

---

### Task 3: Compose builder + golden fixture

**Files:**
- Create: `generator/lib/compose.js`
- Create: `generator/tests/fixtures/reference-compose.yml`
- Test: `generator/tests/compose.test.js`

**Interfaces:**
- Consumes: `IMAGES` from `services.js`, config object from `defaultState()`.
- Produces: `buildCompose(state)` → a `string` (full compose file, trailing newline). Reads `state.pluginUpdater`, `state.fileBrowser`, `state.publishCraftkeeperPort`, `state.craftkeeperPort`, `state.domain`, `state.timezone`, `state.memory`, `state.javaPort`, `state.bedrockPort`, `state.minecraftVersion`, `state.resourceLimits`, `state.cpus`, `state.memoryLimit`, `state.trustedProxies`, `state.trustedHosts`, `state.realtime`, `state.fileBrowserPort`, `state.puid`, `state.pgid`.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/compose.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { buildCompose } from '../lib/compose.js';
import { defaultState } from '../lib/state.js';

function fixture(name) {
  return readFileSync(fileURLToPath(new URL(`./fixtures/${name}`, import.meta.url)), 'utf8');
}

// The reference stack: defaults + both optional services enabled.
function referenceState() {
  return { ...defaultState(), fileBrowser: true };
}

test('reference stack reproduces the golden compose file byte-for-byte', () => {
  assert.equal(buildCompose(referenceState()), fixture('reference-compose.yml'));
});

test('compose.yml contains no literal secret values', () => {
  const state = { ...referenceState(), realtime: true, secrets: {
    appKey: 'base64:SECRET', rconPassword: 'RCONSECRET',
    reverbAppId: '123456', reverbAppKey: 'KEYSECRET', reverbAppSecret: 'SUPERSECRET',
  } };
  const yaml = buildCompose(state);
  assert.ok(!yaml.includes('SUPERSECRET'));
  assert.ok(!yaml.includes('RCONSECRET'));
  assert.ok(yaml.includes('REVERB_APP_SECRET: ${REVERB_APP_SECRET}'));
});

test('disabling File Browser removes its service and its volumes', () => {
  const yaml = buildCompose({ ...defaultState(), fileBrowser: false });
  assert.ok(!yaml.includes('filebrowser:'));
  assert.ok(!yaml.includes('filebrowser_config:'));
  assert.ok(!yaml.includes('filebrowser_database:'));
  assert.ok(yaml.includes('  minecraft:'));
  assert.ok(yaml.includes('  craftkeeper_data:'));
});

test('disabling the plugin updater removes the service and minecraft depends_on', () => {
  const yaml = buildCompose({ ...defaultState(), pluginUpdater: false });
  assert.ok(!yaml.includes('plugin-updater:'));
  assert.ok(!yaml.includes('service_completed_successfully'));
});

test('every mounted named volume is declared in the volumes block', () => {
  const yaml = buildCompose({ ...defaultState(), fileBrowser: true });
  const mounted = new Set();
  for (const m of yaml.matchAll(/^\s+- ([a-z_]+):\//gm)) mounted.add(m[1]);
  const volumesBlock = yaml.slice(yaml.indexOf('\nvolumes:'));
  for (const name of mounted) {
    assert.ok(volumesBlock.includes(`  ${name}:`), `volume ${name} is mounted but not declared`);
  }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/compose.test.js`
Expected: FAIL — cannot find module `../lib/compose.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/lib/compose.js`:

```js
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
```

- [ ] **Step 4: Create the golden fixture**

Create `generator/tests/fixtures/reference-compose.yml` with EXACTLY this content (note the blank lines between service blocks and before `volumes:`, and the trailing newline):

```yaml
services:
  craftkeeper:
    image: ghcr.io/carmelosantana/craftkeeper:v1.1.5
    container_name: craftkeeper
    restart: unless-stopped
    depends_on:
      - minecraft
    ports:
      - "8080:8080"
    volumes:
      - minecraft:/minecraft
      - craftkeeper_data:/data
    environment:
      APP_URL: ${CRAFTKEEPER_APP_URL}
      APP_KEY: ${CRAFTKEEPER_APP_KEY}
      MINECRAFT_ROOT: /minecraft
      DATA_ROOT: /data
      DB_CONNECTION: sqlite
      DB_DATABASE: /data/database.sqlite
      QUEUE_CONNECTION: database
      CACHE_STORE: database
      SESSION_DRIVER: database
      BROADCAST_CONNECTION: log
    healthcheck:
      test: ["CMD", "curl", "--fail", "--silent", "http://127.0.0.1:8080/up"]
      interval: 30s
      timeout: 5s
      retries: 3

  plugin-updater:
    image: ghcr.io/carmelosantana/minecraft-plugin-updater:latest
    pull_policy: always
    restart: "no"
    volumes:
      - minecraft:/minecraft

  minecraft:
    image: 05jchambers/legendary-minecraft-geyser-floodgate:latest
    container_name: minecraft
    restart: unless-stopped
    depends_on:
      plugin-updater:
        condition: service_completed_successfully
    ports:
      - "25565:25565"
      - "19132:19132"
      - "19132:19132/udp"
    volumes:
      - minecraft:/minecraft
    stdin_open: true
    tty: true
    entrypoint: ["/bin/bash", "/scripts/start.sh"]
    environment:
      Port: "25565"
      BedrockPort: "19132"
      TZ: America/New_York
      MaxMemory: 4096
      QuietCurl: "Y"

  filebrowser:
    image: filebrowser/filebrowser:latest
    restart: unless-stopped
    ports:
      - "8081:80"
    volumes:
      - minecraft:/srv/minecraft
      - filebrowser_config:/config
      - filebrowser_database:/database
    environment:
      PUID: "999"
      PGID: "999"
      TZ: America/New_York
    command: --config /config/filebrowser.json --database /database/filebrowser.db --root /srv

volumes:
  minecraft:
  craftkeeper_data:
  filebrowser_config:
  filebrowser_database:
```

If the golden test reports a mismatch, diff the builder output against the fixture and fix whichever is wrong — do NOT loosen the assertion. Regenerate the exact bytes if needed:
`cd generator && node -e "import('./lib/compose.js').then(async m => { const s = (await import('./lib/state.js')).defaultState(); s.fileBrowser = true; process.stdout.write(m.buildCompose(s)); })" > tests/fixtures/reference-compose.yml`
— but only after eyeballing that output against the block above.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd generator && node --test tests/compose.test.js`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add generator/lib/compose.js generator/tests/compose.test.js generator/tests/fixtures/reference-compose.yml
git commit -m "feat(generator): compose builder with derived volumes and golden fixture"
```

---

### Task 4: .env builder

**Files:**
- Create: `generator/lib/env.js`
- Test: `generator/tests/env.test.js`

**Interfaces:**
- Consumes: config object with `state.secrets` populated (shape from `generateSecrets()`), `state.domain`, `state.realtime`.
- Produces: `buildEnv(state)` → a `string` (trailing newline). Always includes `CRAFTKEEPER_APP_KEY`, `CRAFTKEEPER_APP_URL`, `CRAFTKEEPER_RCON_PASSWORD`; includes the three `REVERB_*` lines only when `state.realtime` is true.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/env.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildEnv } from '../lib/env.js';
import { defaultState } from '../lib/state.js';

function stateWithSecrets(overrides = {}) {
  return {
    ...defaultState(),
    secrets: {
      appKey: 'base64:APPKEY', rconPassword: 'RCONPW',
      reverbAppId: '424242', reverbAppKey: 'RKEY', reverbAppSecret: 'RSECRET',
    },
    ...overrides,
  };
}

test('env embeds app key, url, and rcon password', () => {
  const env = buildEnv(stateWithSecrets());
  assert.ok(env.includes('CRAFTKEEPER_APP_KEY=base64:APPKEY'));
  assert.ok(env.includes('CRAFTKEEPER_APP_URL=http://localhost:8080'));
  assert.ok(env.includes('CRAFTKEEPER_RCON_PASSWORD=RCONPW'));
});

test('reverb vars appear only when realtime is enabled', () => {
  assert.ok(!buildEnv(stateWithSecrets()).includes('REVERB_APP_SECRET'));
  const on = buildEnv(stateWithSecrets({ realtime: true }));
  assert.ok(on.includes('REVERB_APP_ID=424242'));
  assert.ok(on.includes('REVERB_APP_KEY=RKEY'));
  assert.ok(on.includes('REVERB_APP_SECRET=RSECRET'));
});

test('env warns not to commit the file', () => {
  assert.match(buildEnv(stateWithSecrets()), /version control/i);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/env.test.js`
Expected: FAIL — cannot find module `../lib/env.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/lib/env.js`:

```js
export function buildEnv(s) {
  const lines = [
    '# Generated by the CraftKeeper compose generator.',
    '# Keep this file OUT of version control — it holds real secrets.',
    '',
    `CRAFTKEEPER_APP_KEY=${s.secrets.appKey}`,
    `CRAFTKEEPER_APP_URL=${s.domain}`,
    '',
    '# RCON password. Not read automatically by the stack — copy this value into',
    '# /minecraft/server.properties (rcon.password=...) AND into CraftKeeper',
    "# onboarding's RCON step. See README.md.",
    `CRAFTKEEPER_RCON_PASSWORD=${s.secrets.rconPassword}`,
  ];
  if (s.realtime) {
    lines.push(
      '',
      '# Laravel Reverb (realtime console). REVERB_APP_SECRET must stay private.',
      `REVERB_APP_ID=${s.secrets.reverbAppId}`,
      `REVERB_APP_KEY=${s.secrets.reverbAppKey}`,
      `REVERB_APP_SECRET=${s.secrets.reverbAppSecret}`,
    );
  }
  return lines.join('\n') + '\n';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd generator && node --test tests/env.test.js`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add generator/lib/env.js generator/tests/env.test.js
git commit -m "feat(generator): .env builder"
```

---

### Task 5: Next-steps builder

**Files:**
- Create: `generator/lib/steps.js`
- Test: `generator/tests/steps.test.js`

**Interfaces:**
- Consumes: config object with `state.secrets.rconPassword` and `state.domain`.
- Produces: `buildNextSteps(state)` → a Markdown `string` beginning with `## Next steps`, containing the exact `rcon.password=<generated>` line, the `docker compose up -d` command, and the domain URL.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/steps.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildNextSteps } from '../lib/steps.js';
import { buildEnv } from '../lib/env.js';
import { defaultState } from '../lib/state.js';

function stateWithSecrets() {
  return {
    ...defaultState(),
    secrets: { appKey: 'base64:APPKEY', rconPassword: 'RCONPW-123',
      reverbAppId: '424242', reverbAppKey: 'RKEY', reverbAppSecret: 'RSECRET' },
  };
}

test('next steps embed the generated rcon password and up command', () => {
  const steps = buildNextSteps(stateWithSecrets());
  assert.ok(steps.startsWith('## Next steps'));
  assert.ok(steps.includes('rcon.password=RCONPW-123'));
  assert.ok(steps.includes('docker compose up -d'));
  assert.ok(steps.includes('http://localhost:8080'));
});

test('the rcon password in next steps matches the one in .env', () => {
  const s = stateWithSecrets();
  const pw = s.secrets.rconPassword;
  assert.ok(buildNextSteps(s).includes(`rcon.password=${pw}`));
  assert.ok(buildEnv(s).includes(`CRAFTKEEPER_RCON_PASSWORD=${pw}`));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/steps.test.js`
Expected: FAIL — cannot find module `../lib/steps.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/lib/steps.js`:

```js
export function buildNextSteps(s) {
  return [
    '## Next steps',
    '',
    '1. Save `compose.yml` and `.env` together in the same directory.',
    '',
    '2. Enable RCON on the Minecraft server. After the first start creates',
    '   `/minecraft/server.properties`, set these lines:',
    '',
    '   ```',
    '   enable-rcon=true',
    `   rcon.password=${s.secrets.rconPassword}`,
    '   rcon.port=25575',
    '   ```',
    '',
    '   then restart the `minecraft` service.',
    '',
    '3. Start the stack:',
    '',
    '   ```bash',
    '   docker compose up -d',
    '   ```',
    '',
    `4. Open ${s.domain} and complete onboarding. In the RCON step, use host`,
    '   `minecraft`, port `25575`, and the password above.',
    '',
  ].join('\n');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd generator && node --test tests/steps.test.js`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add generator/lib/steps.js generator/tests/steps.test.js
git commit -m "feat(generator): next-steps builder"
```

---

### Task 6: README builder

**Files:**
- Create: `generator/lib/readme.js`
- Test: `generator/tests/readme.test.js`

**Interfaces:**
- Consumes: config object (uses `state.pluginUpdater`, `state.fileBrowser`, `state.realtime`, `state.secrets`, `state.domain`) and `buildNextSteps` from `steps.js`.
- Produces: `buildReadme(state)` → a Markdown `string` that lists the enabled services, describes the files, folds in the next-steps section, and carries a security note.

- [ ] **Step 1: Write the failing test**

Create `generator/tests/readme.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildReadme } from '../lib/readme.js';
import { defaultState } from '../lib/state.js';

function stateWithSecrets(overrides = {}) {
  return {
    ...defaultState(),
    secrets: { appKey: 'base64:APPKEY', rconPassword: 'RCONPW',
      reverbAppId: '424242', reverbAppKey: 'RKEY', reverbAppSecret: 'RSECRET' },
    ...overrides,
  };
}

test('readme lists File Browser only when enabled', () => {
  assert.ok(!buildReadme(stateWithSecrets()).includes('File Browser'));
  assert.ok(buildReadme(stateWithSecrets({ fileBrowser: true })).includes('File Browser'));
});

test('readme folds in the next-steps and a security note', () => {
  const readme = buildReadme(stateWithSecrets());
  assert.ok(readme.includes('## Next steps'));
  assert.ok(readme.includes('rcon.password=RCONPW'));
  assert.match(readme, /never commit/i);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd generator && node --test tests/readme.test.js`
Expected: FAIL — cannot find module `../lib/readme.js`.

- [ ] **Step 3: Write minimal implementation**

Create `generator/lib/readme.js`:

```js
import { buildNextSteps } from './steps.js';

export function buildReadme(s) {
  const services = [
    'CraftKeeper control panel',
    'Legendary Java Minecraft (Geyser + Floodgate)',
  ];
  if (s.pluginUpdater) services.push('Minecraft plugin updater (runs once before the server starts)');
  if (s.fileBrowser) services.push('File Browser (web file manager for the server volume)');

  return [
    '# CraftKeeper stack',
    '',
    'Generated by the CraftKeeper compose generator.',
    '',
    '## What this stack runs',
    '',
    ...services.map((line) => `- ${line}`),
    '',
    '## Files',
    '',
    '- `compose.yml` — the Docker Compose definition.',
    '- `.env` — secrets and settings. **Keep this out of version control.**',
    '',
    buildNextSteps(s),
    '## Security note',
    '',
    `The \`.env\` file contains a freshly generated application key${s.realtime ? ' and Reverb secret' : ''}.`,
    'Treat it as sensitive and never commit it to a repository.',
    '',
  ].join('\n');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd generator && node --test tests/readme.test.js`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add generator/lib/readme.js generator/tests/readme.test.js
git commit -m "feat(generator): README builder"
```

---

### Task 7: Bundle (zip) with vendored fflate

**Files:**
- Create: `generator/vendor/fflate.min.js` (vendored, pinned)
- Create: `generator/lib/bundle.js`
- Test: `generator/tests/bundle.test.js`

**Interfaces:**
- Consumes: `buildCompose`, `buildEnv`, `buildReadme`, and `fflate`'s `zipSync`/`strToU8`.
- Produces: `buildFileMap(state)` → `{ 'compose.yml', '.env', 'README.md' }` (string values); `buildZip(state)` → `Uint8Array` (a zip archive of those three files).

- [ ] **Step 1: Vendor fflate (pinned)**

Download the pinned ESM browser build and save it as `generator/vendor/fflate.min.js`:

```bash
curl -fsSL https://unpkg.com/fflate@0.8.2/esm/browser.js -o generator/vendor/fflate.min.js
```

Then prepend a provenance header (edit the file so its first lines are):

```js
// fflate 0.8.2 — vendored, not npm-installed. MIT License (c) 2020 Arjun Barrett.
// Source: https://unpkg.com/fflate@0.8.2/esm/browser.js
// Provides ESM named exports: zipSync, unzipSync, strToU8, strFromU8, etc.
```

Confirm it exposes the expected named exports:
`cd generator && node -e "import('./vendor/fflate.min.js').then(m => console.log(['zipSync','unzipSync','strToU8','strFromU8'].map(k => typeof m[k])))"`
Expected: `[ 'function', 'function', 'function', 'function' ]`. If any is `undefined`, the wrong build was fetched — re-fetch the `esm/browser.js` artifact for 0.8.2.

- [ ] **Step 2: Write the failing test**

Create `generator/tests/bundle.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unzipSync, strFromU8 } from '../vendor/fflate.min.js';
import { buildFileMap, buildZip } from '../lib/bundle.js';
import { defaultState } from '../lib/state.js';

function stateWithSecrets() {
  return {
    ...defaultState(),
    secrets: { appKey: 'base64:APPKEY', rconPassword: 'RCONPW',
      reverbAppId: '424242', reverbAppKey: 'RKEY', reverbAppSecret: 'RSECRET' },
  };
}

test('file map has exactly the three expected files', () => {
  const map = buildFileMap(stateWithSecrets());
  assert.deepEqual(Object.keys(map).sort(), ['.env', 'README.md', 'compose.yml']);
});

test('zip round-trips to the same three files with expected content', () => {
  const zip = buildZip(stateWithSecrets());
  assert.ok(zip instanceof Uint8Array);
  const out = unzipSync(zip);
  assert.deepEqual(Object.keys(out).sort(), ['.env', 'README.md', 'compose.yml']);
  assert.ok(strFromU8(out['compose.yml']).startsWith('services:'));
  assert.ok(strFromU8(out['.env']).includes('CRAFTKEEPER_APP_KEY=base64:APPKEY'));
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd generator && node --test tests/bundle.test.js`
Expected: FAIL — cannot find module `../lib/bundle.js`.

- [ ] **Step 4: Write minimal implementation**

Create `generator/lib/bundle.js`:

```js
import { zipSync, strToU8 } from '../vendor/fflate.min.js';
import { buildCompose } from './compose.js';
import { buildEnv } from './env.js';
import { buildReadme } from './readme.js';

export function buildFileMap(s) {
  return {
    'compose.yml': buildCompose(s),
    '.env': buildEnv(s),
    'README.md': buildReadme(s),
  };
}

export function buildZip(s) {
  const entries = {};
  for (const [name, content] of Object.entries(buildFileMap(s))) {
    entries[name] = strToU8(content);
  }
  return zipSync(entries);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd generator && node --test tests/bundle.test.js`
Expected: PASS (2 tests).

- [ ] **Step 6: Run the whole suite**

Run: `cd generator && node --test`
Expected: PASS — all tests across all files green.

- [ ] **Step 7: Commit**

```bash
git add generator/vendor/fflate.min.js generator/lib/bundle.js generator/tests/bundle.test.js
git commit -m "feat(generator): zip bundle via vendored fflate"
```

---

### Task 8: UI shell (form, live preview, actions)

**Files:**
- Create: `generator/index.html`
- Create: `generator/styles.css`
- Create: `generator/main.js`

**Interfaces:**
- Consumes: `defaultState`, `generateSecrets`, `buildCompose`, `buildEnv`, `buildReadme`, `buildNextSteps`, `buildZip`.
- Produces: no exports — this is the browser entry point. `main.js` binds form controls (by element id matching the `bindings` list) to a single `state`, regenerates all four previews on any input, and wires copy / download / regenerate.

This task has no `node --test` coverage (it is DOM-bound); it is verified manually in Step 4 including a real `docker compose config` validation of the generated file.

- [ ] **Step 1: Create the HTML shell**

Create `generator/index.html`:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CraftKeeper — Compose Generator</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <header>
    <h1>CraftKeeper Compose Generator</h1>
    <p>Build a ready-to-run Docker Compose stack. Nothing is uploaded — everything happens in your browser.</p>
  </header>

  <main>
    <section class="form" aria-label="Configuration">
      <label>Panel URL (APP_URL)
        <input id="domain" type="text" />
      </label>
      <label>Timezone
        <input id="timezone" type="text" />
      </label>
      <label>Server memory (MB)
        <input id="memory" type="number" min="1024" step="512" />
      </label>

      <fieldset>
        <legend>Optional services</legend>
        <label class="check"><input id="pluginUpdater" type="checkbox" /> Plugin updater</label>
        <label class="check"><input id="fileBrowser" type="checkbox" /> File Browser</label>
      </fieldset>

      <details class="advanced">
        <summary>Advanced</summary>

        <label class="check"><input id="publishCraftkeeperPort" type="checkbox" /> Publish panel port to the host</label>
        <label>Panel host port
          <input id="craftkeeperPort" type="number" />
        </label>
        <label>TRUSTED_PROXIES (blank to omit)
          <input id="trustedProxies" type="text" placeholder="*" />
        </label>
        <label>TRUSTED_HOSTS (blank to omit)
          <input id="trustedHosts" type="text" placeholder="panel.example.com" />
        </label>
        <label class="check"><input id="realtime" type="checkbox" /> Enable realtime console (Reverb)</label>

        <label>Minecraft Java host port
          <input id="javaPort" type="number" />
        </label>
        <label>Minecraft Bedrock host port
          <input id="bedrockPort" type="number" />
        </label>
        <label>Minecraft version pin (blank = latest)
          <input id="minecraftVersion" type="text" placeholder="1.21.7" />
        </label>
        <label class="check"><input id="resourceLimits" type="checkbox" /> Set CPU/memory limits</label>
        <label>CPUs
          <input id="cpus" type="number" />
        </label>
        <label>Memory limit
          <input id="memoryLimit" type="text" placeholder="16g" />
        </label>

        <label>File Browser host port
          <input id="fileBrowserPort" type="number" />
        </label>
        <label>File Browser PUID
          <input id="puid" type="number" />
        </label>
        <label>File Browser PGID
          <input id="pgid" type="number" />
        </label>
      </details>

      <div class="actions">
        <button id="regenerate" type="button">Regenerate secrets</button>
        <button id="download" type="button" class="primary">Download bundle (.zip)</button>
      </div>
    </section>

    <section class="preview" aria-label="Preview">
      <nav class="tabs">
        <button data-tab="compose" class="active" type="button">compose.yml</button>
        <button data-tab="env" type="button">.env</button>
        <button data-tab="readme" type="button">README.md</button>
        <button data-tab="steps" type="button">Next steps</button>
      </nav>
      <div id="panel-compose" data-panel><button data-copy="compose" class="copy" type="button">Copy</button><pre id="preview-compose"></pre></div>
      <div id="panel-env" data-panel hidden><button data-copy="env" class="copy" type="button">Copy</button><pre id="preview-env"></pre></div>
      <div id="panel-readme" data-panel hidden><button data-copy="readme" class="copy" type="button">Copy</button><pre id="preview-readme"></pre></div>
      <div id="panel-steps" data-panel hidden><button data-copy="steps" class="copy" type="button">Copy</button><pre id="preview-steps"></pre></div>
    </section>
  </main>

  <script type="module" src="main.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create the stylesheet**

Create `generator/styles.css`:

```css
:root {
  --bg: #0f1115; --panel: #171a21; --border: #262b36;
  --text: #e6e8ec; --muted: #9aa3b2; --accent: #4f9dff; --accent-fg: #0b1220;
}
* { box-sizing: border-box; }
body {
  margin: 0; background: var(--bg); color: var(--text);
  font: 15px/1.5 system-ui, sans-serif;
}
header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); }
header h1 { margin: 0 0 .25rem; font-size: 1.4rem; }
header p { margin: 0; color: var(--muted); }
main { display: grid; grid-template-columns: 360px 1fr; gap: 1rem; padding: 1rem 2rem; align-items: start; }
@media (max-width: 860px) { main { grid-template-columns: 1fr; } }
.form { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 1rem; }
.form label { display: block; margin: .6rem 0; color: var(--muted); font-size: .85rem; }
.form label.check { color: var(--text); }
.form input[type=text], .form input[type=number] {
  display: block; width: 100%; margin-top: .2rem; padding: .45rem .55rem;
  background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text);
}
.form input[type=checkbox] { margin-right: .4rem; }
fieldset { border: 1px solid var(--border); border-radius: 8px; margin: .8rem 0; }
legend { color: var(--muted); padding: 0 .4rem; }
details.advanced { border: 1px solid var(--border); border-radius: 8px; padding: .5rem .8rem; margin-top: .8rem; }
details.advanced summary { cursor: pointer; color: var(--accent); }
.actions { display: flex; gap: .5rem; margin-top: 1rem; }
button { font: inherit; padding: .5rem .8rem; border-radius: 6px; border: 1px solid var(--border); background: var(--bg); color: var(--text); cursor: pointer; }
button.primary { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); font-weight: 600; }
.preview { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: .5rem; min-width: 0; }
.tabs { display: flex; gap: .25rem; margin-bottom: .5rem; flex-wrap: wrap; }
.tabs button.active { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
[data-panel] { position: relative; }
.copy { position: absolute; top: .5rem; right: .5rem; z-index: 1; }
pre { margin: 0; padding: 1rem; background: var(--bg); border-radius: 8px; overflow-x: auto; white-space: pre; font: 13px/1.5 ui-monospace, monospace; }
```

- [ ] **Step 3: Create the wiring**

Create `generator/main.js`:

```js
import { defaultState } from './lib/state.js';
import { generateSecrets } from './lib/secrets.js';
import { buildCompose } from './lib/compose.js';
import { buildEnv } from './lib/env.js';
import { buildReadme } from './lib/readme.js';
import { buildNextSteps } from './lib/steps.js';
import { buildZip } from './lib/bundle.js';

const state = defaultState();
state.secrets = generateSecrets();

const $ = (id) => document.getElementById(id);

// id -> how to read/write the control. Ids match generator/index.html.
const bindings = [
  ['domain', 'value'], ['timezone', 'value'], ['memory', 'number'],
  ['pluginUpdater', 'checked'], ['fileBrowser', 'checked'],
  ['publishCraftkeeperPort', 'checked'], ['craftkeeperPort', 'number'],
  ['trustedProxies', 'value'], ['trustedHosts', 'value'], ['realtime', 'checked'],
  ['javaPort', 'number'], ['bedrockPort', 'number'], ['minecraftVersion', 'value'],
  ['resourceLimits', 'checked'], ['cpus', 'number'], ['memoryLimit', 'value'],
  ['fileBrowserPort', 'number'], ['puid', 'number'], ['pgid', 'number'],
];

function readForm() {
  for (const [id, kind] of bindings) {
    const el = $(id);
    if (!el) continue;
    if (kind === 'checked') state[id] = el.checked;
    else if (kind === 'number') state[id] = Number(el.value);
    else state[id] = el.value;
  }
}

function writeForm() {
  for (const [id, kind] of bindings) {
    const el = $(id);
    if (!el) continue;
    if (kind === 'checked') el.checked = state[id];
    else el.value = state[id];
  }
}

const previews = {
  compose: () => buildCompose(state),
  env: () => buildEnv(state),
  readme: () => buildReadme(state),
  steps: () => buildNextSteps(state),
};

function render() {
  for (const [name, fn] of Object.entries(previews)) {
    const pre = $(`preview-${name}`);
    if (pre) pre.textContent = fn();
  }
}

function setupTabs() {
  document.querySelectorAll('[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-panel]').forEach((p) => (p.hidden = true));
      document.querySelectorAll('[data-tab]').forEach((b) => b.classList.remove('active'));
      $(`panel-${btn.dataset.tab}`).hidden = false;
      btn.classList.add('active');
    });
  });
}

function setupCopy() {
  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      await navigator.clipboard.writeText(previews[btn.dataset.copy]());
      const original = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(() => (btn.textContent = original), 1200);
    });
  });
}

function setupDownload() {
  $('download').addEventListener('click', () => {
    const blob = new Blob([buildZip(state)], { type: 'application/zip' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'craftkeeper-stack.zip';
    a.click();
    URL.revokeObjectURL(url);
  });
}

function setupRegenerate() {
  $('regenerate').addEventListener('click', () => {
    state.secrets = generateSecrets();
    render();
  });
}

writeForm();
document.querySelectorAll('input, select').forEach((el) =>
  el.addEventListener('input', () => { readForm(); render(); }),
);
setupTabs();
setupCopy();
setupDownload();
setupRegenerate();
render();
```

- [ ] **Step 4: Verify manually in a browser (real acceptance)**

ES modules require http(s), not `file://`. Serve the folder and open it:

```bash
cd generator && python3 -m http.server 8099
```

Open `http://localhost:8099/` and confirm:
1. All four preview tabs render; `compose.yml` shows the default stack (plugin-updater on, File Browser absent).
2. Toggling **File Browser** on makes the `filebrowser:` service AND the `filebrowser_config:` / `filebrowser_database:` volumes appear; toggling it off removes both.
3. Toggling **Enable realtime** switches `BROADCAST_CONNECTION` to `reverb` and adds the three `${REVERB_*}` references; the `.env` tab gains the `REVERB_*` values.
4. **Regenerate secrets** changes the `.env` app key while `compose.yml` stays identical (proves secrets never leak into compose).
5. Click **Download bundle** and validate the real output with Docker (this creates NO containers — `config` only renders and validates):

```bash
cd /tmp && rm -rf ck-gen-check && mkdir ck-gen-check && cd ck-gen-check
unzip ~/Downloads/craftkeeper-stack.zip
docker compose config >/dev/null && echo "COMPOSE OK"
```

Expected: `COMPOSE OK` with no errors or warnings about undefined volumes. Clean up: `cd /tmp && rm -rf ck-gen-check`.

- [ ] **Step 5: Commit**

```bash
git add generator/index.html generator/styles.css generator/main.js
git commit -m "feat(generator): UI shell with live preview, copy, and zip download"
```

---

### Task 9: GitHub Pages deployment workflow

**Files:**
- Create: `.github/workflows/generator-pages.yml`

**Interfaces:**
- No code interface. Publishes the static `generator/` directory to GitHub Pages on pushes to `main` that touch `generator/**`, plus manual dispatch.

- [ ] **Step 1: Resolve pinned action SHAs**

The workflow pins each third-party action to a full commit SHA (per the repo's supply-chain standards). Resolve the current SHA for each tag and use it in Step 2:

```bash
for ref in actions/checkout@v4 actions/configure-pages@v5 actions/upload-pages-artifact@v3 actions/deploy-pages@v4; do
  name="${ref%@*}"; tag="${ref#*@}"
  sha=$(gh api "repos/$name/git/refs/tags/$tag" --jq '.object.sha')
  echo "$name@$sha # $tag"
done
```

Record the four `name@sha # tag` lines; substitute them into the `uses:` fields below (replace the `<SHA:...>` placeholders with the real SHAs from this command's output).

- [ ] **Step 2: Write the workflow**

Create `.github/workflows/generator-pages.yml`:

```yaml
name: Deploy compose generator to Pages

on:
  push:
    branches: [main]
    paths: ['generator/**', '.github/workflows/generator-pages.yml']
  workflow_dispatch:

permissions:
  contents: read

concurrency:
  group: generator-pages
  cancel-in-progress: true

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@<SHA:actions/checkout> # v4
      - uses: actions/configure-pages@<SHA:actions/configure-pages> # v5
      - uses: actions/upload-pages-artifact@<SHA:actions/upload-pages-artifact> # v3
        with:
          path: generator

  deploy:
    needs: build
    runs-on: ubuntu-latest
    permissions:
      pages: write
      id-token: write
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - id: deployment
        uses: actions/deploy-pages@<SHA:actions/deploy-pages> # v4
```

- [ ] **Step 3: Validate the workflow file**

Run (if `actionlint` is available): `actionlint .github/workflows/generator-pages.yml`
Otherwise validate YAML syntax: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/generator-pages.yml')); print('YAML OK')"`
Expected: no errors / `YAML OK`. Confirm by eye that no `uses:` line still contains a `<SHA:...>` placeholder and none uses `pull_request_target`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/generator-pages.yml
git commit -m "ci(generator): deploy the compose generator to GitHub Pages"
```

- [ ] **Step 5: Handoff note (manual, not code)**

After merge, a repo admin must set Pages source to **GitHub Actions** (Settings → Pages) once; the tool then publishes at `https://carmelosantana.github.io/craftkeeper/`. Flag this in the final report — it cannot be done from code.

---

## Notes & deliberate deviations from the spec

- **No `yaml.js` module.** The spec sketched a small YAML helper. In practice the fixed shapes are clearer and less error-prone as literal lines inside `compose.js`, and dropping the module keeps the surface smaller (YAGNI). The spec's actual requirement — "structured string generation, not a YAML library" — is met.
- **`APP_URL` uses `${CRAFTKEEPER_APP_URL}` interpolation** (value in `.env`) rather than a literal, matching the reference stack's own convention and keeping `compose.yml` fully deterministic and secret-free.
- **Reverb values are interpolated** (`${REVERB_*}`) so the actual secret never lands in `compose.yml`; only `.env` holds it.

## Self-review checklist (completed by plan author)

- **Spec coverage:** placement (Task 9, `generator/` + Pages) · config scope with advanced drawer (Task 8 `<details>`) · full bundle output compose/.env/README/steps (Tasks 3–7) · zip download (Task 7–8) · derived volumes (Task 3) · Web Crypto secrets (Task 2) · golden test vs reference stack (Task 3) · `node --test` (all tasks) · supply-chain-pinned fflate + SHA-pinned actions (Tasks 7, 9). No spec requirement left without a task.
- **Placeholder scan:** the only intentional placeholders are the `<SHA:...>` action pins, resolved by the concrete command in Task 9 Step 1 — actionable, not vague.
- **Type consistency:** `state` field names are identical across `services.js` DEFAULTS, `compose.js`, `env.js`, `steps.js`, `readme.js`, and `main.js` `bindings`. `generateSecrets()` keys (`appKey`, `rconPassword`, `reverbAppId`, `reverbAppKey`, `reverbAppSecret`) are used identically everywhere. Builder names (`buildCompose`, `buildEnv`, `buildReadme`, `buildNextSteps`, `buildFileMap`, `buildZip`) match their call sites.
