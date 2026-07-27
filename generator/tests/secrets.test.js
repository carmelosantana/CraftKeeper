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
