<?php

namespace App\Http\Controllers\Api\V1;

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\ConfigDiffBuilder;
use App\Config\ConfigFormatRegistry;
use App\Config\DiscoveredFile;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\FileSnapshot;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiPrincipal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConfigFileDetailResource;
use App\Http\Resources\Api\V1\ConfigFileResource;
use App\Http\Resources\Api\V1\OperationResource;
use App\Models\Operation;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Policies\ApiOperationPolicy;
use App\Support\Api\ApiError;
use App\Support\Api\CursorPaginator;
use App\Support\Api\IdempotencyKeyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * /api/v1/config/* — Task 17. Every read here (`config:read`) reuses the
 * exact same App\Filesystem\MinecraftFilesystem / App\Config\
 * ConfigFormatRegistry / App\Config\Schemas\ConfigSchemaRegistry /
 * App\Config\ConfigDiffBuilder::redactSecrets() pipeline the web
 * App\Http\Controllers\ConfigController already uses — no new redaction
 * or discovery logic is introduced here. Every write
 * (`config:propose`/`config:apply`) is a thin pass-through to
 * App\Config\ConfigChangeService / App\Operations\OperationService — the
 * SAME domain services the web UI calls.
 *
 * `apply()` is the crux this controller exists to get right: it can ONLY
 * execute (App\Operations\OperationService::execute()) a config.apply/
 * config.restore operation that a HUMAN has ALREADY moved to
 * OperationStatus::Approved through the web UI
 * (App\Http\Controllers\ConfigController::approve(), which itself calls
 * OperationService::approve() with a real, session-authenticated
 * App\Models\User). There is no method on this controller — or on
 * App\Policies\ApiOperationPolicy — that can ever move an operation TO
 * Approved. See docs/architecture/decisions.md's Task 17 entry for the
 * full reconciliation.
 */
class ConfigController extends Controller
{
    use ResolvesApiPrincipal;

    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigSchemaRegistry $schemas,
        private readonly ConfigChangeService $changes,
        private readonly OperationService $operations,
        private readonly IdempotencyKeyStore $idempotency,
        private readonly ApiOperationPolicy $policy,
    ) {}

    /**
     * GET /api/v1/config/files — metadata-only inventory, cursor-paginated
     * over the discovered file list, ordered by path.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = collect($this->filesystem->discover())
            ->sortBy(fn ($file) => $file->path->relativePath)
            ->values()
            ->map(fn ($file) => [
                'path' => $file->path->relativePath,
                'filename' => basename($file->path->relativePath),
                'format' => $file->format,
                'category' => $file->category->value,
                'recognized' => $file->recognized,
                'provenance' => $file->provenance,
                'schema_title' => $this->schemas->forPath($file->path)?->title,
                'size_bytes' => $file->sizeBytes,
            ]);

        $page = CursorPaginator::paginate($items, $request, fn (array $item) => $item['path']);

        return ConfigFileResource::collection($page['items'])
            ->additional(['meta' => CursorPaginator::meta($page['hasMore'], $page['nextCursor'])]);
    }

    /**
     * GET /api/v1/config/files/{path} — a single file's metadata and
     * REDACTED content, with an ETag derived from its real sha256 (Task
     * 17's ambiguity resolution #4: "ETags for config reads"). A matching
     * `If-None-Match` short-circuits to 304 with no body.
     */
    public function show(Request $request, string $path): JsonResponse
    {
        $resolved = $this->resolvePath($path);
        $current = $this->readOrAbort($resolved);
        $etag = '"'.$current->sha256.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        $adapter = $this->formats->for($current);
        $schema = $this->schemas->forPath($resolved);
        $validation = $adapter->validate($current->contents, $schema);
        $discovered = $this->findDiscovered($resolved);

        $data = [
            'path' => $resolved->relativePath,
            'filename' => basename($resolved->relativePath),
            'format' => $schema->format ?? strtolower(pathinfo($resolved->relativePath, PATHINFO_EXTENSION)),
            'category' => $discovered?->category->value,
            'provenance' => $discovered?->provenance,
            'recognized' => $schema !== null,
            'schema_title' => $schema?->title,
            'size_bytes' => strlen($current->contents),
            'modified_at' => date(DATE_ATOM, $current->mtime),
            'base_sha256' => $current->sha256,
            'contents' => ConfigDiffBuilder::redactSecrets($adapter, $schema, $current->contents),
            'validation' => [
                'valid' => $validation->valid,
                'diagnostic_count' => count($validation->diagnostics),
            ],
        ];

        return (new ConfigFileDetailResource($data))->response()->header('ETag', $etag);
    }

    /**
     * GET /api/v1/config/proposals — cursor-paginated, most-recent-first
     * list of config.apply/config.restore operations.
     */
    public function listProposals(Request $request): AnonymousResourceCollection
    {
        $operations = Operation::query()
            ->whereIn('type', [OperationType::ConfigApply, OperationType::ConfigRestore])
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $page = CursorPaginator::paginate($operations, $request, fn (Operation $o) => $o->id);

        return OperationResource::collection($page['items'])
            ->additional(['meta' => CursorPaginator::meta($page['hasMore'], $page['nextCursor'])]);
    }

    public function showProposal(Operation $operation): JsonResponse
    {
        $this->guardConfigOperation($operation);

        return (new OperationResource($operation))->response();
    }

    /**
     * POST /api/v1/config/proposals — `config:propose`. NEVER writes
     * anything (App\Config\ConfigChangeService::propose()'s own
     * contract) — it only ever creates a Proposed Operation a human must
     * separately approve through the web UI. A stale `base_sha256`
     * throws App\Config\Exceptions\ConfigConflict, rendered as 409 by the
     * exception closure registered in bootstrap/app.php. A repeated
     * `Idempotency-Key` returns the ORIGINAL proposal rather than
     * creating a duplicate — see App\Support\Api\IdempotencyKeyStore.
     */
    public function propose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'base_sha256' => ['required', 'string', 'size:64'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.path' => ['required', 'string', 'max:255'],
            'changes.*.kind' => ['required', Rule::in(['replace', 'add', 'remove'])],
            'changes.*.value' => ['nullable'],
        ]);

        $changes = array_values(array_map(fn (array $c): ConfigChange => match ($c['kind']) {
            'replace' => ConfigChange::replace($c['path'], $c['value'] ?? null),
            'add' => ConfigChange::add($c['path'], $c['value'] ?? null),
            default => ConfigChange::remove($c['path']),
        }, $data['changes']));

        $changeRequest = new ConfigChangeRequest($data['path'], $data['base_sha256'], $changes);
        $author = OperationAuthor::user($this->apiUser($request)->getKey());

        $operation = $this->idempotency->resolve(
            $this->apiToken($request),
            'config.proposals.create',
            $request->header('Idempotency-Key'),
            $data,
            fn (): Operation => $this->changes->propose($changeRequest, $author),
        );

        return (new OperationResource($operation))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v1/config/proposals/{operation}/apply — `config:apply`.
     * Executes (never approves) an operation a human already approved.
     * App\Policies\ApiOperationPolicy::apply() is the single source of
     * truth for "is this operation in a state config:apply may touch";
     * false gets 409 (a state conflict, not a permission error — the
     * SCOPE already authorized this token to call this endpoint at all).
     */
    public function apply(Request $request, Operation $operation): JsonResponse
    {
        $this->guardConfigOperation($operation);

        if (! $this->policy->apply($this->apiUser($request), $operation)) {
            return ApiError::response(
                $request,
                409,
                'operation_not_approved',
                'This operation has not been approved by a human administrator, or is not in an applicable state.',
            );
        }

        $executed = $this->operations->execute($operation->id);

        return (new OperationResource($executed))->response();
    }

    // -----------------------------------------------------------------

    private function guardConfigOperation(Operation $operation): void
    {
        abort_unless(in_array($operation->type, [OperationType::ConfigApply, OperationType::ConfigRestore], true), 404);
    }

    private function resolvePath(string $rawPath): MinecraftPath
    {
        try {
            return MinecraftPath::fromUserInput($rawPath);
        } catch (UnsafeMinecraftPath) {
            abort(404);
        }
    }

    private function readOrAbort(MinecraftPath $path): FileSnapshot
    {
        try {
            return $this->filesystem->read($path);
        } catch (MinecraftFileNotFound|NotARegularFile) {
            abort(404);
        }
    }

    private function findDiscovered(MinecraftPath $path): ?DiscoveredFile
    {
        foreach ($this->filesystem->discover() as $file) {
            if ($file->path->relativePath === $path->relativePath) {
                return $file;
            }
        }

        return null;
    }
}
