<?php

namespace Tests\Support;

use ArrayAccess;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Wraps the raw Laravel\Mcp\Transport\JsonRpcResponse::toArray() shape
 * returned by an in-process MCP call (tests/Concerns/CallsMcp.php) with
 * array access to a successful call's decoded JSON payload
 * (`$result['status']`, matching the brief's Step-1 test) plus a small set
 * of assertions for the failure shapes this task's security boundary
 * tests need.
 *
 * A genuine App\Mcp\Support\McpGuard denial (`Response::error($reason)`)
 * surfaces in TWO different JSON-RPC shapes depending on which primitive
 * produced it — a real distinction inside laravel/mcp's own
 * Laravel\Mcp\Server\Methods\Concerns\InteractsWithResponses::
 * toJsonRpcResponse(), not a bug in this application: `CallTool`
 * implements `Laravel\Mcp\Server\Contracts\Errable`, so a Tool's
 * `Response::error()` becomes a normal `result.isError: true` payload;
 * `ReadResource`/`GetPrompt` do NOT implement `Errable`, so the identical
 * `Response::error()` call gets converted into a THROWN
 * `Laravel\Mcp\Exceptions\JsonRpcException` (code -32603) instead — a
 * top-level JSON-RPC protocol `error` object with THIS application's own
 * denial message as its text (never a generic/raw one, since
 * App\Mcp\Support\McpGuard::run() already catches every Throwable inside
 * its own callback before anything reaches here). Both shapes mean
 * exactly the same thing — "this call was denied, no data was returned"
 * — and assertDenied() below treats both as equivalent, while
 * assertMcpToolNotFound() specifically requires the DIFFERENT, distinct
 * -32602 "not found" shape a genuinely unregistered tool/resource name
 * produces, so the two failure modes can never be confused with each
 * other in a test.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class McpCallResult implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $data = [];

    private bool $isError = false;

    private ?string $message = null;

    private ?int $protocolErrorCode = null;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private readonly array $raw)
    {
        if (array_key_exists('error', $raw)) {
            $this->isError = true;
            $this->message = (string) ($raw['error']['message'] ?? '');
            $this->protocolErrorCode = $raw['error']['code'] ?? null;

            return;
        }

        $result = $raw['result'] ?? [];
        $this->isError = (bool) ($result['isError'] ?? false);

        $text = null;

        foreach ([...($result['content'] ?? []), ...($result['contents'] ?? [])] as $item) {
            if (isset($item['text'])) {
                $text = $item['text'];
                break;
            }
        }

        if ($text === null) {
            return;
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            $this->data = $decoded;

            return;
        }

        $this->message = $text;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Asserts this call resolved to the JSON-RPC "method/tool not found"
     * shape — i.e. no such tool (or resource) is registered on the server
     * at all, the exact signature App\Mcp\Servers\CraftKeeperServer's
     * closed tool/resource set produces for anything not in its
     * `$tools`/`$resources` arrays (Laravel\Mcp\Server\Methods\CallTool /
     * ReadResource, via their own ResolvesResources/`tools()->first(...,
     * fn () => throw new JsonRpcException(...))` code — never something
     * this application's own code decides at runtime).
     */
    public function assertMcpToolNotFound(): static
    {
        Assert::assertArrayHasKey('error', $this->raw, 'Expected a JSON-RPC error response for an unregistered tool/resource, got a normal result.');
        Assert::assertSame(-32602, $this->protocolErrorCode, 'Expected JSON-RPC error code -32602 (invalid params / not found).');
        Assert::assertStringContainsString('not found', strtolower((string) $this->message), 'Expected a "not found" error message.');

        return $this;
    }

    /**
     * Asserts this call was denied by App\Policies\McpGrantPolicy (via
     * App\Mcp\Support\McpGuard) — EITHER JSON-RPC shape (see class
     * docblock) — with an optional substring the denial reason must
     * contain. Explicitly distinct from assertMcpToolNotFound(): a -32602
     * "not found" response fails this assertion, so a test can never
     * mistake a genuinely unregistered tool for a scope/grant denial.
     */
    public function assertDenied(?string $reasonContains = null): static
    {
        Assert::assertTrue($this->isError, 'Expected the MCP call to be denied.');
        Assert::assertNotSame(-32602, $this->protocolErrorCode, 'Expected a scope/grant denial, got a "tool/resource not found" response instead.');

        if ($reasonContains !== null) {
            Assert::assertStringContainsString($reasonContains, (string) $this->message, 'The denial reason did not contain the expected text.');
        }

        return $this;
    }

    public function assertOk(): static
    {
        Assert::assertArrayNotHasKey('error', $this->raw, 'Expected a successful MCP result, got a JSON-RPC protocol error: '.($this->raw['error']['message'] ?? ''));
        Assert::assertFalse($this->isError, 'Expected a successful MCP result, got isError: true: '.($this->message ?? ''));

        return $this;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('McpCallResult is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('McpCallResult is read-only.');
    }
}
