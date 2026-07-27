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
