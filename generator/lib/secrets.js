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
