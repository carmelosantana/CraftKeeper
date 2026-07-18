<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ApiIdempotencyKey;
use App\Models\ConfigChangePayload;
use App\Models\Operation;
use App\Models\Secret;
use App\Models\Setting;
use App\Models\User;
use App\Support\SupportBundleService;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

/**
 * Recursively walks every entry of a zip archive and returns one giant
 * haystack string of every member's contents concatenated together —
 * deliberately crude (this is a security test, not a parser) so a canary
 * hiding ANYWHERE in ANY file of the bundle, at any nesting depth, is
 * still caught.
 */
function bundleHaystack(string $zipPath): string
{
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBe(true);

    $haystack = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $haystack .= $name."\n";
        $contents = $zip->getFromIndex($i);

        if (is_string($contents)) {
            $haystack .= $contents."\n";
        }
    }

    $zip->close();

    return $haystack;
}

/*
|--------------------------------------------------------------------------
| The security crux: every secret canary, seeded anywhere the brief
| names, must be byte-for-byte absent from the generated bundle.
|--------------------------------------------------------------------------
*/
it('excludes every seeded secret canary from the generated support bundle', function () {
    // Canary 1: a Secret value (e.g. the RCON password).
    $secretCanary = 'CANARY-SECRET-RCON-PASSWORD-9f8e7d6c5b4a';
    Secret::put('rcon.password', $secretCanary);
    Secret::put('ai.api_key', 'CANARY-SECRET-AI-KEY-1a2b3c4d5e6f');

    // Canary 2: an AI chat message (App\Models\AiMessage::content).
    $chatCanary = 'CANARY-CHAT-CONTENT-a1b2c3d4e5f6-please-do-not-export-me';
    $conversation = AiConversation::query()->create(['title' => 'canary conversation']);
    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $chatCanary,
    ]);

    // Canary 3: a config-change secret value (App\Models\ConfigChangePayload,
    // the one genuinely raw, encrypted-at-rest secret value in the config
    // pipeline — Task 8).
    $configSecretCanary = 'CANARY-CONFIG-SECRET-VALUE-plugins-foo-token';
    $operation = Operation::factory()->create();
    ConfigChangePayload::query()->create([
        'operation_id' => $operation->id,
        'changes' => [
            ['kind' => 'set', 'path' => 'rcon.password', 'value' => $configSecretCanary],
        ],
    ]);

    // Canary 4: a live API token — both its one-time plaintext AND its
    // persisted hash must never appear.
    $token = $this->admin->createToken('canary token', ['server:read']);
    $tokenPlainText = $token->plainTextToken;
    $tokenHash = $token->accessToken->getAttributes()['token'];

    // Canary 5: an idempotency key's request hash (internal bookkeeping,
    // never meant to be surfaced — see App\Models\ApiIdempotencyKey).
    ApiIdempotencyKey::query()->create([
        'personal_access_token_id' => $token->accessToken->id,
        'endpoint' => 'POST /api/v1/config/canary',
        'idempotency_key' => 'CANARY-IDEMPOTENCY-KEY-value',
        'request_hash' => str_pad('CANARY-REQUEST-HASH-value', 64, '0'),
        'operation_id' => $operation->id,
    ]);

    $zipPath = app(SupportBundleService::class)->create();

    expect($zipPath)->toBeFile();

    $haystack = bundleHaystack($zipPath);

    expect($haystack)->not->toContain($secretCanary)
        ->and($haystack)->not->toContain('CANARY-SECRET-AI-KEY-1a2b3c4d5e6f')
        ->and($haystack)->not->toContain($chatCanary)
        ->and($haystack)->not->toContain($configSecretCanary)
        ->and($haystack)->not->toContain($tokenPlainText)
        ->and($haystack)->not->toContain($tokenHash)
        ->and($haystack)->not->toContain('CANARY-IDEMPOTENCY-KEY-value')
        ->and($haystack)->not->toContain('CANARY-REQUEST-HASH-value');

    @unlink($zipPath);
});

it('excludes full uploaded JAR bytes from the support bundle', function () {
    // A canary-shaped "JAR" dropped under the data root's quarantine
    // directory, exactly where a real uploaded artifact would briefly
    // live (App\Plugins\Concerns\QuarantinesArtifacts) — the bundle must
    // never recurse into it.
    $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
    $quarantineDir = $dataRoot.'/quarantine/canary-token';
    @mkdir($quarantineDir, 0755, true);
    file_put_contents($quarantineDir.'/artifact.jar', 'CANARY-JAR-BYTES-should-never-be-exported');

    $zipPath = app(SupportBundleService::class)->create();
    $haystack = bundleHaystack($zipPath);

    expect($haystack)->not->toContain('CANARY-JAR-BYTES-should-never-be-exported');

    $zip = new ZipArchive;
    $zip->open($zipPath);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        expect($zip->getNameIndex($i))->not->toEndWith('.jar');
    }
    $zip->close();

    @unlink($zipPath);
    @unlink($quarantineDir.'/artifact.jar');
    @rmdir($quarantineDir);
    @rmdir($dataRoot.'/quarantine');
});

/*
|--------------------------------------------------------------------------
| The bundle must still be genuinely useful: real health/versions/
| permissions/redacted-settings/checksum data, honestly reported.
|--------------------------------------------------------------------------
*/
it('includes honest, non-secret diagnostic content', function () {
    Setting::put('minecraft.server_path', '/minecraft');

    $zipPath = app(SupportBundleService::class)->create();
    $haystack = bundleHaystack($zipPath);

    expect($haystack)->toContain('manifest.json')
        ->and($haystack)->toContain('versions.json')
        ->and($haystack)->toContain('health.json')
        ->and($haystack)->toContain('/minecraft');

    @unlink($zipPath);
});

it('records a sha256 checksum for every file included in the bundle', function () {
    $zipPath = app(SupportBundleService::class)->create();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();

    expect($manifest)->toHaveKey('checksums');
    expect($manifest['checksums'])->not->toBeEmpty();

    foreach ($manifest['checksums'] as $file => $sha256) {
        expect($sha256)->toMatch('/^[0-9a-f]{64}$/');
    }

    @unlink($zipPath);
});
