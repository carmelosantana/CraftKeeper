<?php

use App\Config\ConfigDiscoveryService;
use App\Console\Exceptions\RconAuthFailed;
use App\Console\MinecraftRconClient;
use App\Console\RconCommand;
use App\Console\StreamRconTransport;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Task 20's ambiguity resolution #7: an opt-in smoke test against the
 * REAL `05jchambers/legendary-minecraft-geyser-floodgate:latest` image —
 * a genuine Paper server with Geyser/Floodgate/ViaVersion actually
 * installed, not a fixture or a fake protocol implementation. Skipped by
 * default; set `CRAFTKEEPER_LEGENDARY_SMOKE=1` to run it (manually, or
 * from a nightly job with registry/Docker access).
 *
 * Exists specifically to close the one gap Task 10's own ambiguity
 * resolution left open on purpose: RCON's WIRE PROTOCOL correctness is
 * unit-verified only, against `Tests\fixtures\rcon\FakeRconTransport`
 * (see App\Console\MinecraftRconClient's own docblock) — never against a
 * real server's actual auth handshake. This test performs exactly that
 * real handshake, via the SAME production `MinecraftRconClient` class
 * this application ships, with no test double anywhere in the path.
 *
 * What this test does NOT do, on purpose:
 *   - Never runs any plugin-mutation test (install/update/disable/
 *     remove/rollback) against this container — only a read-only file
 *     discovery pass and a single safe (`list`) RCON command. A real
 *     user's live server must never be targeted by anything resembling
 *     this stack's plugin-lifecycle scenarios.
 *   - Never touches any OTHER container/volume on the (potentially
 *     shared) Docker host — every resource this test creates carries the
 *     `craftkeeper-legendary-smoke-*` name, and cleanup
 *     (`destroyLegendaryFixture()`) removes ONLY those two exact names,
 *     never a bulk/global prune.
 *   - Never assumes the EULA is accepted anywhere but inside this one
 *     ephemeral container's own environment (`EULA=TRUE` passed only to
 *     the `docker run` this test issues).
 *
 * The image is pinned by DIGEST in the recorded result (see
 * `recordResult()`) precisely because `:latest` is mutable — a future
 * run against a different underlying image is visible in the log this
 * test writes, not silently assumed to be "the same" build.
 */
const LEGENDARY_SMOKE_IMAGE = '05jchambers/legendary-minecraft-geyser-floodgate:latest';

const LEGENDARY_SMOKE_CONTAINER = 'craftkeeper-legendary-smoke-test';

const LEGENDARY_SMOKE_VOLUME = 'craftkeeper-legendary-smoke-data';

const LEGENDARY_SMOKE_RCON_PASSWORD = 'craftkeeper-legendary-smoke-rcon';

const LEGENDARY_SMOKE_RCON_PORT = 25575;

function legendarySmokeEnabled(): bool
{
    return filter_var(getenv('CRAFTKEEPER_LEGENDARY_SMOKE') ?: '0', FILTER_VALIDATE_BOOLEAN);
}

/**
 * @param  list<string>  $args
 */
function legendaryDocker(array $args, int $timeoutSeconds = 60): Process
{
    $process = new Process(['docker', ...$args]);
    $process->setTimeout($timeoutSeconds);
    $process->run();

    return $process;
}

/**
 * Removes ONLY this test's own two exact-named resources. Never a
 * bulk/global prune — see this file's own docblock and the task's
 * Docker guardrail.
 */
function destroyLegendaryFixture(): void
{
    legendaryDocker(['rm', '-f', LEGENDARY_SMOKE_CONTAINER]);
    legendaryDocker(['volume', 'rm', LEGENDARY_SMOKE_VOLUME]);
}

function recordLegendaryResult(string $line): void
{
    $path = storage_path('logs/legendary-smoke-result.log');
    file_put_contents($path, '['.now()->toIso8601String().'] '.$line.PHP_EOL, FILE_APPEND);
}

it('boots a real Legendary Paper+Geyser+Floodgate image, authenticates over real RCON, and runs a safe list command', function () {
    $dockerVersion = legendaryDocker(['version', '--format', '{{.Server.Version}}'], 10);
    expect($dockerVersion->isSuccessful())
        ->toBeTrue('Docker must be reachable to run this opt-in smoke test: '.$dockerVersion->getErrorOutput());

    // Start from a known-clean slate — exact-name only.
    destroyLegendaryFixture();

    try {
        // Pull explicitly (rather than relying on `docker run` to pull
        // implicitly) so the digest below reflects EXACTLY what gets run.
        $pull = legendaryDocker(['pull', LEGENDARY_SMOKE_IMAGE], 300);
        expect($pull->isSuccessful())->toBeTrue('docker pull failed: '.$pull->getErrorOutput());

        $digestProcess = legendaryDocker([
            'inspect', LEGENDARY_SMOKE_IMAGE, '--format', '{{index .RepoDigests 0}}',
        ]);
        expect($digestProcess->isSuccessful())->toBeTrue();
        $digest = trim($digestProcess->getOutput());
        expect($digest)->toMatch('/^[\w.\/-]+@sha256:[0-9a-f]{64}$/');

        recordLegendaryResult("pinned image digest: {$digest}");

        // A disposable named volume, pre-seeded with an RCON-enabled
        // server.properties + a pre-accepted eula.txt BEFORE the real
        // image's own startup script ever runs — verified empirically
        // (not assumed) that this image's start.sh only GENERATES those
        // two files when they are absent, so pre-writing them here is
        // respected rather than overwritten.
        $volumeCreate = legendaryDocker(['volume', 'create', LEGENDARY_SMOKE_VOLUME]);
        expect($volumeCreate->isSuccessful())->toBeTrue();

        $seed = legendaryDocker([
            'run', '--rm',
            '-v', LEGENDARY_SMOKE_VOLUME.':/minecraft',
            'alpine:latest',
            'sh', '-c',
            'printf "enable-rcon=true\nrcon.port=%d\nrcon.password=%s\n" '
                .LEGENDARY_SMOKE_RCON_PORT.' '.LEGENDARY_SMOKE_RCON_PASSWORD.' > /minecraft/server.properties'
                .' && printf "eula=true\n" > /minecraft/eula.txt',
        ], 60);
        expect($seed->isSuccessful())->toBeTrue('seeding the fixture volume failed: '.$seed->getErrorOutput());

        $run = legendaryDocker([
            'run', '-d', '--name', LEGENDARY_SMOKE_CONTAINER,
            '-e', 'EULA=TRUE',
            '-v', LEGENDARY_SMOKE_VOLUME.':/minecraft',
            LEGENDARY_SMOKE_IMAGE,
        ], 30);
        expect($run->isSuccessful())->toBeTrue('docker run failed: '.$run->getErrorOutput());

        // Wait for Paper/Geyser/Floodgate readiness — the same "Done (Xs)!
        // For help, type "help"" line every Paper/vanilla server prints
        // once world generation and every plugin's onEnable() have
        // finished (see App\Server\LogParser's own docblock for this
        // exact literal pattern).
        $ready = false;
        $deadline = microtime(true) + 240;

        while (microtime(true) < $deadline) {
            $logs = legendaryDocker(['logs', LEGENDARY_SMOKE_CONTAINER], 10);
            if (str_contains($logs->getOutput().$logs->getErrorOutput(), 'Done (')) {
                $ready = true;
                break;
            }
            usleep(500_000);
        }

        expect($ready)->toBeTrue('Paper server did not report readiness within 240s.');

        $ip = trim(legendaryDocker([
            'inspect', LEGENDARY_SMOKE_CONTAINER,
            '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}',
        ])->getOutput());
        expect($ip)->not->toBe('');

        // --- Real RCON authentication + a safe `list` command ---
        // The exact production class this application ships — no fake
        // transport anywhere in this path.
        $client = new MinecraftRconClient(
            new StreamRconTransport,
            $ip,
            LEGENDARY_SMOKE_RCON_PORT,
            LEGENDARY_SMOKE_RCON_PASSWORD,
        );

        $listResponse = $client->execute(RconCommand::from('list'));
        expect($listResponse->body)->toContain('players online');

        recordLegendaryResult('real RCON auth + `list` succeeded: '.$listResponse->body);

        // A wrong password must still be rejected by the real server —
        // proves this isn't a permissive/no-auth fluke.
        $badClient = new MinecraftRconClient(new StreamRconTransport, $ip, LEGENDARY_SMOKE_RCON_PORT, 'not-the-password');
        expect(fn () => $badClient->execute(RconCommand::from('list')))->toThrow(RconAuthFailed::class);

        // --- Read-only discovery against the REAL server's own files ---
        // `docker cp` the live volume out to a local, disposable
        // directory and point CraftKeeper's OWN ConfigDiscoveryService at
        // it — proving discovery works against a genuine Paper install's
        // real file layout, not just this repo's curated fixtures. Purely
        // read-only: nothing is copied back, nothing inside the
        // container/volume is modified.
        $localCopy = sys_get_temp_dir().'/craftkeeper-legendary-smoke-discovery-'.uniqid();
        mkdir($localCopy, 0755, true);

        try {
            $copy = legendaryDocker([
                'cp', LEGENDARY_SMOKE_CONTAINER.':/minecraft/.', $localCopy,
            ], 60);
            expect($copy->isSuccessful())->toBeTrue('docker cp failed: '.$copy->getErrorOutput());

            config(['craftkeeper.minecraft_root' => $localCopy]);
            $discovered = app(ConfigDiscoveryService::class)->discover();
            $paths = array_map(fn ($file) => $file->path->relativePath, $discovered);

            expect($paths)->toContain('server.properties');

            recordLegendaryResult('discovered '.count($paths).' config files from the real server, including server.properties');
        } finally {
            // Task 20 fix pass: this was `legendaryDocker(['rm', '-rf',
            // $localCopy])` — a bogus `docker rm -rf <host-path>`. `docker
            // rm` removes CONTAINERS by name/id, not host paths, so this
            // never deleted the local temp directory `mkdir()`-ed above
            // (and legendaryDocker()'s return value is never checked, so
            // the failure was silently swallowed). Use the actual
            // filesystem API for a HOST directory.
            File::deleteDirectory($localCopy);
        }

        recordLegendaryResult('PASSED end to end against digest '.$digest);
    } finally {
        destroyLegendaryFixture();
    }
})->skip(
    fn () => ! legendarySmokeEnabled(),
    'Opt-in only — set CRAFTKEEPER_LEGENDARY_SMOKE=1 (manually, or from a nightly job with Docker registry access) to run this against the real Legendary image.',
);
