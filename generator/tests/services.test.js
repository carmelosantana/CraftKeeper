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
