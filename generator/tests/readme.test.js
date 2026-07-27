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
