<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class HealthController extends Controller
{
    /**
     * Report application readiness for container orchestration.
     *
     * Every check reflects a real, freshly-evaluated condition (a live
     * database connection attempt and an actual filesystem probe) rather
     * than a static "ok" — a broken check must be able to fail this route.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'data_directory' => $this->checkDataDirectory(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, path: string}
     */
    private function checkDataDirectory(): array
    {
        $path = (string) config('craftkeeper.data_root');

        if ($path !== '' && ! File::isDirectory($path)) {
            // $force = true silences the underlying mkdir() warning so an
            // unwritable parent reports through the status check below
            // instead of surfacing a raw PHP warning.
            File::makeDirectory($path, 0755, true, true);
        }

        if ($path === '' || ! File::isDirectory($path) || ! is_writable($path)) {
            return ['status' => 'error', 'path' => $path];
        }

        return ['status' => 'ok', 'path' => $path];
    }
}
