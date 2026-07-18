<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServerStatusResource;
use App\Server\ServerStatusService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/server/status — the `server:read` scope's only endpoint.
 * Purely reads App\Server\ServerStatusService::snapshot(), the exact same
 * already-tested aggregation App\Http\Controllers\ServerController (the
 * web UI) uses; no new domain logic lives here.
 */
class ServerController extends Controller
{
    public function __construct(
        private readonly ServerStatusService $status,
    ) {}

    public function status(): JsonResponse
    {
        return (new ServerStatusResource($this->status->snapshot()))->response();
    }
}
