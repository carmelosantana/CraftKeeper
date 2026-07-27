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
