<?php

use App\Support\ApiScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Task 17's contract test: openapi.yaml (repo root) must have ZERO drift
 * against the routes actually registered under /api/v1 —
 * routes/api.php. Every registered API route's ->name() is, by
 * convention (see routes/api.php's own docblock), identical to its
 * documented `operationId`, so comparing the two sets by that shared
 * identity catches both directions of drift:
 *
 *   - a route registered in code but never documented (a caller has no
 *     way to discover it), and
 *   - a path/method documented in openapi.yaml that no longer resolves
 *     to a real route (documentation describing something that doesn't
 *     exist).
 *
 * Also does a light structural sanity pass over the document itself
 * (valid YAML, is openapi 3.1, every operation carries an operationId/
 * summary/responses, security scheme + scope list present, webhooks
 * explicitly absent) — not a full OpenAPI 3.1 meta-schema validation
 * (out of scope for this task), but enough to catch the document being
 * hand-edited into an inconsistent shape.
 */
function apiV1Routes(): Collection
{
    return collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'));
}

/**
 * @return array<string, mixed>
 */
function openApiSpec(): array
{
    $path = base_path('openapi.yaml');

    expect(is_file($path))->toBeTrue("openapi.yaml must exist at the repo root; looked at [{$path}].");

    /** @var array<string, mixed> $spec */
    $spec = Yaml::parseFile($path);

    return $spec;
}

/**
 * Flattens openapi.yaml's `paths` into a Collection<string operationId,
 * array{path: string, method: string}>, converting each documented path
 * (e.g. "/api/v1/config/files/{path}") into the SAME uri shape Laravel's
 * Route::uri() reports (no leading slash: "api/v1/config/files/{path}").
 *
 * @return Collection<string, array{path: string, method: string}>
 */
function documentedOperations(): Collection
{
    $spec = openApiSpec();
    $operations = collect();

    foreach ($spec['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (! in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $operationId = $operation['operationId'] ?? null;
            expect($operationId)->not->toBeNull("Every operation must declare an operationId; missing for {$method} {$path}.");

            $operations->put($operationId, [
                'path' => ltrim((string) $path, '/'),
                'method' => strtoupper((string) $method),
            ]);
        }
    }

    return $operations;
}

it('is valid YAML declaring OpenAPI 3.1', function () {
    $spec = openApiSpec();

    expect($spec['openapi'] ?? null)->toStartWith('3.1')
        ->and($spec['info']['title'] ?? null)->not->toBeNull()
        ->and($spec['info']['version'] ?? null)->not->toBeNull()
        ->and($spec['paths'] ?? null)->toBeArray();
});

it('documents bearer-token auth and every App\Support\ApiScope value', function () {
    $spec = openApiSpec();

    $scheme = $spec['components']['securitySchemes']['ApiToken'] ?? null;
    expect($scheme)->not->toBeNull()
        ->and($scheme['type'])->toBe('http')
        ->and($scheme['scheme'])->toBe('bearer');

    $documentedScopes = array_keys($scheme['x-scopes'] ?? []);
    sort($documentedScopes);

    $realScopes = ApiScope::values();
    sort($realScopes);

    expect($documentedScopes)->toBe($realScopes);
});

it('explicitly documents the absence of webhooks in V1', function () {
    $spec = openApiSpec();

    expect($spec['webhooks'] ?? null)->toBeNull()
        ->and($spec['info']['description'] ?? '')->toContain('no webhooks');
});

it('every registered /api/v1 route is documented in openapi.yaml by matching operationId, path, and method', function () {
    $registered = apiV1Routes();
    $documented = documentedOperations();

    expect($registered)->not->toBeEmpty();

    foreach ($registered as $route) {
        $name = $route->getName();
        expect($name)->not->toBeNull("Route [{$route->uri()}] must be named (its name IS its operationId).");

        $doc = $documented->get($name);
        expect($doc)->not->toBeNull("Route [{$route->methods()[0]} {$route->uri()}] (operationId '{$name}') is not documented in openapi.yaml.");

        expect($doc['path'])->toBe($route->uri())
            ->and(in_array($doc['method'], $route->methods(), true))->toBeTrue(
                "openapi.yaml documents {$doc['method']} for operationId '{$name}', but the route only accepts [".implode(',', $route->methods()).'].'
            );
    }
});

it('every documented openapi.yaml operation is a real, registered /api/v1 route', function () {
    $registeredNames = apiV1Routes()->map(fn ($route) => $route->getName())->all();

    foreach (documentedOperations() as $operationId => $doc) {
        expect(in_array($operationId, $registeredNames, true))->toBeTrue(
            "openapi.yaml documents operationId '{$operationId}' ({$doc['method']} /{$doc['path']}), but no such route is registered."
        );
    }
});

/**
 * Composes `servers[0].url` with a documented path the same naive way a
 * generated client does (plain string concatenation), then normalizes
 * the result the way a URL parser would: collapsing any RUN of repeated
 * "/" into one. Deliberately does NOT collapse a repeated PATH SEGMENT
 * (".../api/v1/api/v1/...") — that's exactly the double-prefix bug this
 * test exists to catch, and a real HTTP client never "fixes" it either.
 */
function composedOperationUrl(string $serverUrl, string $documentedPath): string
{
    $composed = $serverUrl.$documentedPath;

    return (string) preg_replace('#/{2,}#', '/', $composed);
}

/**
 * Task 17 follow-up (Fix 1): the previous contract tests above compare
 * the RAW documented path directly against Laravel's route URI, so they
 * never notice that a generated OpenAPI client doesn't request the raw
 * documented path — it requests `servers[0].url` + path. With
 * `servers[0].url: /api/v1` and full `/api/v1/...` path keys, that
 * composition doubled the prefix (`/api/v1/api/v1/server/status`), a
 * broken URL no earlier test caught. This test composes+normalizes
 * every documented path the same way a real client would and asserts
 * the result is a real registered route URI with the `api/v1` prefix
 * appearing exactly once — neither doubled nor missing.
 */
it('composes servers[0].url with every documented path to a real, single-prefixed /api/v1 route (catches double- or missing-prefix drift)', function () {
    $spec = openApiSpec();
    $serverUrl = $spec['servers'][0]['url'] ?? null;

    expect($serverUrl)->not->toBeNull('openapi.yaml must declare servers[0].url — a generated client composes it with every documented path.');

    $registeredUris = apiV1Routes()->map(fn ($route) => $route->uri())->all();
    expect($registeredUris)->not->toBeEmpty();

    foreach ($spec['paths'] ?? [] as $path => $methods) {
        $composed = composedOperationUrl($serverUrl, (string) $path);
        $composedUri = ltrim($composed, '/');

        expect(substr_count($composedUri, 'api/v1'))->toBe(
            1,
            "servers[0].url [{$serverUrl}] composed with documented path [{$path}] produced [{$composed}], which does not contain the api/v1 prefix exactly once (double- or missing-prefix)."
        );

        expect(in_array($composedUri, $registeredUris, true))->toBeTrue(
            "servers[0].url [{$serverUrl}] composed with documented path [{$path}] produced [{$composed}], which is not a real registered /api/v1 route."
        );
    }
});

it('every operation documents scope security, a summary, and both a success and an error response', function () {
    $spec = openApiSpec();

    foreach ($spec['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (! in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $label = "{$method} {$path}";

            expect($operation['summary'] ?? null)->not->toBeNull("{$label} is missing a summary.");
            expect($operation['security'] ?? null)->not->toBeEmpty("{$label} is missing a security requirement (its scope).");

            $responses = $operation['responses'] ?? [];
            $hasSuccess = array_key_exists('200', $responses) || array_key_exists('201', $responses);
            expect($hasSuccess)->toBeTrue("{$label} is missing a 200/201 success response.");

            expect($responses['403'] ?? $responses['default'] ?? null)
                ->not->toBeNull("{$label} does not document a 403 (missing-scope) response.");
        }
    }
});

/**
 * The crux, documented: NO operation anywhere in the spec approves a
 * mutation, and the proposal/approval separation is called out explicitly
 * in the top-level description.
 */
it('documents the proposal/approval separation and never documents an approve operation', function () {
    $spec = openApiSpec();

    expect($spec['info']['description'] ?? '')->toContain('human')
        ->and(mb_strtolower((string) ($spec['info']['description'] ?? '')))->toContain('approv');

    foreach (documentedOperations() as $operationId => $doc) {
        expect(mb_strtolower($operationId))->not->toContain('approve');
    }
});
