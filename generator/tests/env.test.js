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
