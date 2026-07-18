<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiPrincipal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OperationResource;
use App\Http\Resources\Api\V1\PluginResource;
use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Operations\OperationAuthor;
use App\Plugins\PluginInventoryService;
use App\Plugins\PluginLifecycleService;
use App\Support\Api\CursorPaginator;
use App\Support\Api\IdempotencyKeyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/v1/plugins/* — Task 17. `plugins:read` exposes the SAME installed
 * inventory App\Http\Controllers\PluginController (the web UI) reads, via
 * App\Plugins\PluginInventoryService::reconcile(). `plugins:manage` only
 * ever PROPOSES disable/remove — App\Plugins\PluginLifecycleService's
 * propose*() methods, which never approve or execute anything (Task 15's
 * own contract). Install/update are intentionally NOT exposed via
 * /api/v1 in this task: they require re-resolving a catalog release and
 * downloading/inspecting a real artifact
 * (App\Plugins\PluginLifecycleService::proposeInstall()/proposeUpdate()),
 * a heavier, network-touching flow the web UI's Discover page already
 * owns end-to-end; disable/remove need no such resolution and are enough
 * to exercise the `plugins:manage` scope boundary.
 */
class PluginController extends Controller
{
    use ResolvesApiPrincipal;

    public function __construct(
        private readonly PluginInventoryService $inventory,
        private readonly PluginLifecycleService $lifecycle,
        private readonly IdempotencyKeyStore $idempotency,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->inventory->reconcile();

        $installations = PluginInstallation::query()->orderBy('relative_path')->get();

        $page = CursorPaginator::paginate($installations, $request, fn (PluginInstallation $p) => $p->relative_path);

        return PluginResource::collection($page['items'])
            ->additional(['meta' => CursorPaginator::meta($page['hasMore'], $page['nextCursor'])]);
    }

    public function show(string $filename): JsonResponse
    {
        $this->inventory->reconcile();

        return (new PluginResource($this->installationOrAbort($filename)))->response();
    }

    public function disable(Request $request, string $filename): JsonResponse
    {
        $installation = $this->installationOrAbort($filename);
        $author = OperationAuthor::user($this->apiUser($request)->getKey(), 'api');

        $operation = $this->idempotency->resolve(
            $this->apiToken($request),
            'plugins.disable',
            $request->header('Idempotency-Key'),
            ['filename' => $filename],
            fn (): Operation => $this->lifecycle->proposeDisable($installation, $author),
        );

        return (new OperationResource($operation))->response()->setStatusCode(201);
    }

    public function remove(Request $request, string $filename): JsonResponse
    {
        $installation = $this->installationOrAbort($filename);
        $author = OperationAuthor::user($this->apiUser($request)->getKey(), 'api');

        $operation = $this->idempotency->resolve(
            $this->apiToken($request),
            'plugins.remove',
            $request->header('Idempotency-Key'),
            ['filename' => $filename],
            fn (): Operation => $this->lifecycle->proposeRemove($installation, $author),
        );

        return (new OperationResource($operation))->response()->setStatusCode(201);
    }

    private function installationOrAbort(string $filename): PluginInstallation
    {
        $installation = PluginInstallation::query()->where('relative_path', 'plugins/'.$filename)->first();
        abort_if($installation === null, 404);

        return $installation;
    }
}
