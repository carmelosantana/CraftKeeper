#!/usr/bin/env node
'use strict';

/**
 * Task 20's fake, bounded Source-RCON server for docker-compose.integration.yml.
 *
 * Implements exactly the wire protocol app/Console/MinecraftRconClient.php
 * documents and consumes (see that file's own class docblock): a 4-byte
 * little-endian length header, then a 4-byte request id, a 4-byte type,
 * a NUL-terminated body, and a trailing empty NUL. Types: auth=3, exec=2,
 * response=0 (this server only ever sends type 0 — MinecraftRconClient
 * gates auth success on the request id alone, never the type, exactly so
 * a real server's own type-2 SERVERDATA_AUTH_RESPONSE and this server's
 * simpler type-0-everywhere behavior are both accepted).
 *
 * Deliberately NOT a real Minecraft server: it never plays Minecraft's
 * own protocol on any other port, never persists world state, and only
 * recognizes a small, fixed set of commands (RCON_SAFE_COMMANDS below,
 * a superset of app/Console/CommandPolicy's own "safe" allow-list) —
 * anything else gets a generic "Unknown command" reply, matching a real
 * server's own behavior for a command it doesn't recognize, WITHOUT this
 * fake server having to implement Minecraft's actual command set.
 *
 * Bounded like the real client expects to defend against: rejects any
 * declared length outside app/Console/MinecraftRconClient.php's own
 * MIN/MAX_PACKET_LENGTH range, and caps how many connections/commands it
 * will process at once (see MAX_CONCURRENT_CONNECTIONS) so a runaway
 * test can't turn this into an unbounded resource sink on the shared
 * Docker host.
 */

const net = require('net');

const PORT = parseInt(process.env.RCON_PORT || '25575', 10);
const PASSWORD = process.env.RCON_PASSWORD || 'craftkeeper-integration-rcon';
const HOST = '0.0.0.0';

const MIN_PACKET_LENGTH = 10;
const MAX_PACKET_LENGTH = 1_048_576;
const MAX_CONCURRENT_CONNECTIONS = 16;

const TYPE_RESPONSE = 0;
const TYPE_EXEC = 2;
const TYPE_AUTH = 3;

let activeConnections = 0;
let playersOnline = [];

// A superset of app/Console/CommandPolicy's own "safe" RCON command
// allow-list, plus a couple of harmless extras real integration
// scenarios exercise (op/deop are NOT here — CraftKeeper's own
// CommandPolicy already refuses those at the MCP/safe-console layer;
// this fake server only needs to answer the commands CraftKeeper itself
// is allowed to send).
function handleCommand(body) {
    const command = body.trim();

    if (command === '') {
        // The client's own terminator packet — always an empty body.
        return '';
    }

    if (command === 'list') {
        const names = playersOnline.length > 0 ? playersOnline.join(', ') : '';
        return `There are ${playersOnline.length} of a max of 20 players online: ${names}`;
    }

    if (command === 'seed') {
        return '[CraftKeeper Integration] Seed: [1234567890]';
    }

    if (command === 'whitelist list') {
        return 'There are no whitelisted players';
    }

    if (command === 'banlist') {
        return 'There are no banned players';
    }

    if (command.startsWith('save-')) {
        return 'Saved the game';
    }

    return `Unknown or incomplete command, see below for error\n${command}\n<--[HERE]`;
}

function packInt32LE(value) {
    const buf = Buffer.alloc(4);
    buf.writeInt32LE(value, 0);
    return buf;
}

function buildPacket(requestId, type, body) {
    const bodyBuf = Buffer.from(body, 'utf8');
    const core = Buffer.concat([
        packInt32LE(requestId),
        packInt32LE(type),
        bodyBuf,
        Buffer.from([0x00, 0x00]),
    ]);

    return Buffer.concat([packInt32LE(core.length), core]);
}

const server = net.createServer((socket) => {
    if (activeConnections >= MAX_CONCURRENT_CONNECTIONS) {
        socket.destroy();

        return;
    }

    activeConnections++;
    let authenticated = false;
    let buffer = Buffer.alloc(0);

    socket.on('data', (chunk) => {
        buffer = Buffer.concat([buffer, chunk]);

        // Drain as many complete packets as the buffer currently holds.
        for (;;) {
            if (buffer.length < 4) {
                return;
            }

            const declaredLength = buffer.readInt32LE(0);

            if (declaredLength < MIN_PACKET_LENGTH || declaredLength > MAX_PACKET_LENGTH) {
                socket.destroy();

                return;
            }

            if (buffer.length < 4 + declaredLength) {
                // Wait for the rest of this packet.
                return;
            }

            const requestId = buffer.readInt32LE(4);
            const type = buffer.readInt32LE(8);
            const body = buffer.slice(12, 4 + declaredLength - 2).toString('utf8');

            buffer = buffer.slice(4 + declaredLength);

            if (type === TYPE_AUTH) {
                authenticated = body === PASSWORD;
                const replyId = authenticated ? requestId : -1;
                socket.write(buildPacket(replyId, TYPE_RESPONSE, ''));

                continue;
            }

            if (type === TYPE_EXEC) {
                if (!authenticated) {
                    // A real server simply never answers exec packets
                    // before a successful auth; closing is the bounded,
                    // unambiguous equivalent for this fake server.
                    socket.destroy();

                    return;
                }

                socket.write(buildPacket(requestId, TYPE_RESPONSE, handleCommand(body)));

                continue;
            }

            // Anything else is a protocol violation — refuse rather than
            // guess at a reply.
            socket.destroy();

            return;
        }
    });

    socket.on('error', () => {
        // A reset/aborted connection is expected traffic for a bounded
        // test double, not a server fault worth logging noisily.
    });

    socket.on('close', () => {
        activeConnections = Math.max(0, activeConnections - 1);
    });
});

server.listen(PORT, HOST, () => {
    // eslint-disable-next-line no-console
    console.log(`fake-rcon listening on ${HOST}:${PORT}`);
});
