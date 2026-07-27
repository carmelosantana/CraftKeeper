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
