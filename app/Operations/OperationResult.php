<?php

namespace App\Operations;

/**
 * The outcome of an OperationHandler::execute()/rollback() call. A typed,
 * always-present result — handlers report failure through this value
 * object (successful: false, a stable errorCode), not by throwing, so
 * OperationService never needs to guess whether a caught exception means
 * "the mutation failed" versus "the handler itself is broken". Any
 * exception a handler does let escape is still caught by
 * OperationService::execute() and converted into a failed OperationResult,
 * so a misbehaving handler can never crash the lifecycle.
 */
final class OperationResult
{
    /**
     * @param  array<string, mixed>  $output
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $errorCode,
        public readonly ?string $message,
        public readonly array $output,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function success(?string $message = null, array $output = []): self
    {
        return new self(true, null, $message, $output);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function failure(string $errorCode, ?string $message = null, array $output = []): self
    {
        return new self(false, $errorCode, $message, $output);
    }
}
