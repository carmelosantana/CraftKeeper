<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\CommandPolicy;
use App\Console\CommandRisk;
use App\Console\RconCommandService;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiPrincipal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OperationResource;
use App\Models\Operation;
use App\Operations\OperationAuthor;
use App\Support\Api\ApiError;
use App\Support\Api\CursorPaginator;
use App\Support\Api\IdempotencyKeyStore;
use App\Support\ApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/v1/operations/* — Task 17. `activity:read` is a read-only feed over
 * EVERY Operation regardless of type (config/plugin/rcon/server), reusing
 * App\Http\Resources\Api\V1\OperationResource's already-redacted shape.
 *
 * createRconCommand() is the one endpoint gated by an "any of" scope pair
 * (`rcon:safe` OR `rcon:admin` — see routes/api.php) because which scope
 * is SUFFICIENT depends on the command's own risk classification: a
 * `rcon:safe`-only token may only propose a command
 * App\Console\CommandPolicy::classify() calls Safe; proposing anything
 * Elevated requires `rcon:admin`. Either way this only ever PROPOSES
 * (App\Console\RconCommandService::proposeCommand()) — it never calls the
 * "lighter path" runSafeCommand() (propose+self-approve+execute), because
 * that path calls App\Operations\OperationService::approve(), and NO
 * /api/v1 endpoint may ever reach that method (Task 17's crux — see
 * docs/architecture/decisions.md). A human must always separately approve
 * an API-proposed rcon command through the web Console UI before it can
 * ever execute, exactly like a command proposed through the browser.
 */
class OperationController extends Controller
{
    use ResolvesApiPrincipal;

    public function __construct(
        private readonly CommandPolicy $policy,
        private readonly RconCommandService $commands,
        private readonly IdempotencyKeyStore $idempotency,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $operations = Operation::query()->orderByDesc('id')->limit(1000)->get();

        $page = CursorPaginator::paginate($operations, $request, fn (Operation $o) => $o->id);

        return OperationResource::collection($page['items'])
            ->additional(['meta' => CursorPaginator::meta($page['hasMore'], $page['nextCursor'])]);
    }

    public function show(Operation $operation): JsonResponse
    {
        return (new OperationResource($operation))->response();
    }

    public function createRconCommand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:4096'],
        ]);

        $command = $data['command'];
        $user = $this->apiUser($request);

        if ($this->policy->classify($command) === CommandRisk::Elevated && ! $this->apiToken($request)->can(ApiScope::RconAdmin->value)) {
            return ApiError::response(
                $request,
                403,
                'forbidden_scope',
                'This command is classified Elevated; proposing it requires the rcon:admin scope.',
                ['required_scope' => ApiScope::RconAdmin->value],
            );
        }

        $author = OperationAuthor::user($user->getKey(), 'api');

        $operation = $this->idempotency->resolve(
            $this->apiToken($request),
            'operations.rcon-commands.create',
            $request->header('Idempotency-Key'),
            $data,
            fn (): Operation => $this->commands->proposeCommand($command, $author),
        );

        return (new OperationResource($operation))->response()->setStatusCode(201);
    }
}
