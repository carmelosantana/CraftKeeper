<?php

namespace Tests\Concerns;

use App\Mcp\Servers\CraftKeeperServer;
use App\Models\McpGrant;
use Illuminate\Container\Container;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Support\McpCallResult;

/**
 * Task 18's in-process MCP testing concern: invokes tools/resources
 * against App\Mcp\Servers\CraftKeeperServer directly — no HTTP transport,
 * no real network round-trip — while still exercising the REAL
 * authorization code path: `Laravel\Passport\Passport::actingAsClient()`
 * (Passport's OWN official testing helper) attaches the target
 * App\Models\McpGrant's backing Laravel\Passport\Client to the 'passport'
 * guard via `Laravel\Passport\Guards\TokenGuard::setClient()` — the exact
 * same method production traffic populates after validating a real bearer
 * token (see App\Mcp\Support\McpGuard's own docblock) — so
 * App\Policies\McpGrantPolicy enforcement runs identically in tests and in
 * production.
 *
 * A `null` (or omitted) `$grant` simulates an unauthenticated/anonymous
 * caller — no client is ever attached to the guard, so
 * App\Mcp\Support\McpGuard::resolveGrant() resolves nothing and every
 * scope check is denied, matching a request with no bearer token at all.
 */
trait CallsMcp
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function callMcpTool(?McpGrant $grant, string $tool, array $arguments = []): McpCallResult
    {
        $this->actAsMcpGrant($grant);

        return $this->executeMcp('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function readMcpResource(?McpGrant $grant, string $uri, array $arguments = []): McpCallResult
    {
        $this->actAsMcpGrant($grant);

        return $this->executeMcp('resources/read', ['uri' => $uri, 'arguments' => $arguments]);
    }

    protected function getMcpPrompt(?McpGrant $grant, string $prompt): McpCallResult
    {
        $this->actAsMcpGrant($grant);

        return $this->executeMcp('prompts/get', ['name' => $prompt, 'arguments' => []]);
    }

    protected function actAsMcpGrant(?McpGrant $grant): void
    {
        if ($grant === null) {
            return;
        }

        $client = Client::query()->find($grant->oauth_client_id);

        if (! $client instanceof Client) {
            throw new RuntimeException("No Passport client found for McpGrant [{$grant->id}] (oauth_client_id [{$grant->oauth_client_id}]).");
        }

        Passport::actingAsClient($client, [], 'passport');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function executeMcp(string $method, array $params): McpCallResult
    {
        $server = Container::getInstance()->make(CraftKeeperServer::class, [
            'transport' => new FakeTransporter,
        ]);
        $server->start();

        $request = new JsonRpcRequest(uniqid('mcp-test-', true), $method, $params);

        try {
            $response = (function (JsonRpcRequest $request) {
                /** @var Server $this */
                return $this->runMethodHandle($request, $this->createContext());
            })->call($server, $request);
        } catch (JsonRpcException $e) {
            $response = $e->toJsonRpcResponse();
        }

        if (is_iterable($response)) {
            foreach ($response as $item) {
                $response = $item;
            }
        }

        return new McpCallResult($response->toArray());
    }
}
