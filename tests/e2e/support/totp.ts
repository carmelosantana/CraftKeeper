import { createHmac } from 'node:crypto';

/**
 * Minimal RFC 4226 (HOTP) / RFC 6238 (TOTP) implementation so the e2e
 * suite can generate a real, valid 6-digit code from the Base32 manual
 * setup key Fortify's 2FA enrollment modal displays — the same secret the
 * QR code encodes. No dependency needed: this is ~30 lines of HMAC-SHA1
 * plus Base32 decoding, matching PragmaRX/Google2FA's defaults (SHA1, 6
 * digits, 30-second step) that Fortify uses server-side.
 */

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(base32: string): Buffer {
    const clean = base32.replace(/=+$/, '').toUpperCase().replace(/\s+/g, '');

    let bits = '';
    for (const char of clean) {
        const value = BASE32_ALPHABET.indexOf(char);
        if (value === -1) {
            continue;
        }
        bits += value.toString(2).padStart(5, '0');
    }

    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

function hotp(secret: string, counter: number, digits = 6): string {
    const key = base32Decode(secret);

    const counterBuffer = Buffer.alloc(8);
    counterBuffer.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
    counterBuffer.writeUInt32BE(counter % 2 ** 32, 4);

    const hmac = createHmac('sha1', key).update(counterBuffer).digest();

    const offset = hmac[hmac.length - 1] & 0x0f;
    const binCode =
        ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);

    return (binCode % 10 ** digits).toString().padStart(digits, '0');
}

/** Generate the current 6-digit TOTP code for a Base32 secret. */
export function generateTotp(
    base32Secret: string,
    timeStepSeconds = 30,
    digits = 6,
): string {
    const counter = Math.floor(Date.now() / 1000 / timeStepSeconds);

    return hotp(base32Secret, counter, digits);
}
