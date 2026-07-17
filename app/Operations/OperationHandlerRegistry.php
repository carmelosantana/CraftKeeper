<?php

namespace App\Operations;

/**
 * Resolves an OperationHandler for a given OperationType.
 *
 * As of Task 5, no concrete OperationHandler exists, so this registry is
 * always empty in this app — resolve() always returns null, and
 * OperationService::execute() degrades that into a typed "no handler
 * registered" failure rather than crashing (see
 * OperationService::execute()).
 *
 * Extension convention for later tasks: bind a handler into the container
 * and tag it `operation.handler` — e.g. in a service provider's register():
 *
 *   $this->app->tag(ConfigApplyHandler::class, 'operation.handler');
 *   $this->app->tag(ConfigRestoreHandler::class, 'operation.handler');
 *
 * App\Providers\AppServiceProvider already binds OperationHandlerRegistry
 * as a singleton constructed from every service tagged `operation.handler`
 * (`$app->tagged('operation.handler')`), so tagging a handler is the only
 * step required to wire it up — no changes to OperationService or this
 * class are needed to add a new OperationType's execution.
 */
class OperationHandlerRegistry
{
    /**
     * @var list<OperationHandler>
     */
    private array $handlers = [];

    /**
     * @param  iterable<OperationHandler>  $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->handlers[] = $handler;
        }
    }

    /**
     * Register a handler imperatively (mainly useful in tests — production
     * wiring should prefer the `operation.handler` container tag described
     * in the class docblock).
     */
    public function register(OperationHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * The first registered handler that supports $type, or null if none
     * does.
     */
    public function resolve(OperationType $type): ?OperationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $handler;
            }
        }

        return null;
    }
}
