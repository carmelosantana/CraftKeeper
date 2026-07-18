<?php

namespace App\Support\Api;

use App\Models\ApiIdempotencyKey;
use App\Models\Operation;
use App\Support\Api\Exceptions\IdempotencyKeyConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Task 17's ambiguity resolution #4: "Idempotency-Key header for
 * mutation-proposal creation — a REPEATED key returns the ORIGINAL
 * proposal (not a duplicate)." Every /api/v1 controller action that
 * creates a proposal (config, plugin, rcon) routes its Operation-creating
 * closure through resolve() rather than calling the domain service
 * directly, so all three get identical idempotency semantics from one
 * place.
 *
 * Scoped per (personal access token, endpoint, key) — see the migration's
 * own docblock for why. A repeated key with an IDENTICAL request body
 * returns the original Operation; a repeated key with a DIFFERENT body
 * throws IdempotencyKeyConflict (409) instead of silently returning a
 * mismatched proposal or silently creating a second one.
 */
final class IdempotencyKeyStore
{
    /**
     * @param  array<string, mixed>  $payload  The request body, used only to detect a reused key with a different body — never persisted verbatim.
     * @param  callable(): Operation  $create
     */
    public function resolve(PersonalAccessToken $token, string $endpoint, ?string $key, array $payload, callable $create): Operation
    {
        if ($key === null || $key === '') {
            return $create();
        }

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = $this->find($token, $endpoint, $key);

        if ($existing !== null) {
            return $this->fromExisting($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($token, $endpoint, $key, $hash, $create): Operation {
                $operation = $create();

                ApiIdempotencyKey::query()->create([
                    'personal_access_token_id' => $token->id,
                    'endpoint' => $endpoint,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'operation_id' => $operation->id,
                ]);

                return $operation;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent request carrying the exact
            // same (token, endpoint, key) tuple — the winner's row is now
            // visible; defer to it rather than erroring.
            $existing = $this->find($token, $endpoint, $key);

            abort_if($existing === null, 500, 'Idempotency key race could not be resolved.');

            return $this->fromExisting($existing, $hash);
        }
    }

    private function find(PersonalAccessToken $token, string $endpoint, string $key): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::query()
            ->where('personal_access_token_id', $token->id)
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function fromExisting(ApiIdempotencyKey $existing, string $hash): Operation
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new IdempotencyKeyConflict($existing->idempotency_key);
        }

        return Operation::query()->findOrFail($existing->operation_id);
    }
}
