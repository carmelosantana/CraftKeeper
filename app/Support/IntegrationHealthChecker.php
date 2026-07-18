<?php

namespace App\Support;

use App\Ai\AiManager;
use App\Catalog\CatalogSourceHealth;
use App\Models\McpGrant;
use App\Models\Secret;
use App\Models\Setting;
use App\Plugins\PluginProvenance;
use App\Server\ServerStatusService;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Computes Task 19's ten Connected/Disabled/Degraded/Misconfigured
 * integration rows — the SAME computation for both
 * App\Http\Controllers\IntegrationController's Integrations overview page
 * AND App\Support\SupportBundleService's `health.json`, so the two can
 * never silently disagree about whether e.g. RCON is currently healthy.
 *
 * Every check here reads ALREADY-COMPUTED, passively-recorded state
 * (App\Server\ServerStatusService's snapshot, App\Catalog\
 * CatalogSourceHealth's per-source rows, App\Ai\AiManager's configuration)
 * — this class itself never makes an outbound network call. The
 * "actionable test" a human triggers from the Integrations page
 * (App\Http\Controllers\IntegrationController::test()) is what actually
 * performs a fresh live probe and updates that recorded state; this class
 * only ever reports what is already on file, so calling snapshot() is
 * always cheap and side-effect-free — safe to call from a support bundle
 * export as well as a page render.
 */
final class IntegrationHealthChecker
{
    public function __construct(
        private readonly ServerStatusService $status,
        private readonly AiManager $ai,
        private readonly CatalogSourceHealth $catalogHealth,
        private readonly UmamiScript $umami,
    ) {}

    /**
     * @return list<IntegrationStatus>
     */
    public function snapshot(): array
    {
        return [
            $this->minecraftDirectory(),
            $this->rcon(),
            $this->aiIntegration(),
            $this->catalogSource('catalog', 'CraftKeeper Catalog', PluginProvenance::Catalog),
            $this->catalogSource('hangar', 'Hangar', PluginProvenance::Hangar),
            $this->catalogSource('modrinth', 'Modrinth', PluginProvenance::Modrinth),
            $this->documentation(),
            $this->api(),
            $this->mcp(),
            $this->umamiIntegration(),
        ];
    }

    private function minecraftDirectory(): IntegrationStatus
    {
        $logs = $this->status->snapshot()->logs;

        return new IntegrationStatus(
            key: 'minecraft-directory',
            label: 'Minecraft directory',
            state: $logs->available ? 'connected' : 'misconfigured',
            reason: $logs->reason,
            testable: true,
        );
    }

    private function rcon(): IntegrationStatus
    {
        $configured = filled(Setting::get('rcon.host')) || Secret::configured('rcon.password');

        if (! $configured) {
            return new IntegrationStatus(
                key: 'rcon',
                label: 'RCON',
                state: 'disabled',
                reason: 'No RCON host or password has been configured yet.',
                testable: true,
            );
        }

        $rcon = $this->status->snapshot()->rcon;

        return new IntegrationStatus(
            key: 'rcon',
            label: 'RCON',
            state: $rcon->available ? 'connected' : 'degraded',
            reason: $rcon->reason,
            testable: true,
        );
    }

    private function aiIntegration(): IntegrationStatus
    {
        $detail = $this->ai->healthDetail();

        if ($detail['activeProvider'] === null) {
            return new IntegrationStatus(
                key: 'ai',
                label: 'AI',
                state: 'disabled',
                reason: 'No AI provider is configured.',
                testable: true,
            );
        }

        if (! $detail['configured']) {
            return new IntegrationStatus(
                key: 'ai',
                label: 'AI',
                state: 'misconfigured',
                reason: 'An AI provider is selected but is missing a required base URL, model, or API key.',
                testable: true,
            );
        }

        $health = $detail['health'];

        return new IntegrationStatus(
            key: 'ai',
            label: 'AI',
            state: $health !== null && $health->available ? 'connected' : 'degraded',
            reason: $health?->reason,
            testable: true,
        );
    }

    private function catalogSource(string $key, string $label, PluginProvenance $source): IntegrationStatus
    {
        $snapshot = $this->catalogHealth->snapshot($source);

        if ($snapshot === null) {
            return new IntegrationStatus(
                key: $key,
                label: $label,
                state: 'disabled',
                reason: 'Not checked yet — use "Test" to fetch a live status.',
                testable: true,
            );
        }

        return new IntegrationStatus(
            key: $key,
            label: $label,
            state: $snapshot->status === 'ok' ? 'connected' : 'degraded',
            reason: $snapshot->last_error,
            testable: true,
        );
    }

    private function documentation(): IntegrationStatus
    {
        // App\Ai\DocumentationIndex is a small, curated, in-process
        // dataset with no network dependency at all (see its own
        // docblock) — it structurally cannot be unreachable or
        // misconfigured, so it is always reported connected.
        return new IntegrationStatus(
            key: 'documentation',
            label: 'Official documentation cache',
            state: 'connected',
            reason: 'Built-in curated reference links; no network dependency.',
            testable: true,
        );
    }

    private function api(): IntegrationStatus
    {
        $count = PersonalAccessToken::query()->count();

        return new IntegrationStatus(
            key: 'api',
            label: 'API',
            state: $count > 0 ? 'connected' : 'disabled',
            reason: $count > 0 ? "{$count} active token(s)." : 'No /api/v1 tokens have been created yet.',
            testable: true,
        );
    }

    private function mcp(): IntegrationStatus
    {
        if (! File::exists(storage_path('oauth-private.key')) || ! File::exists(storage_path('oauth-public.key'))) {
            return new IntegrationStatus(
                key: 'mcp',
                label: 'MCP',
                state: 'misconfigured',
                reason: 'OAuth signing keys are missing — MCP cannot authenticate any client.',
                testable: true,
            );
        }

        $activeCount = McpGrant::query()->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return new IntegrationStatus(
            key: 'mcp',
            label: 'MCP',
            state: $activeCount > 0 ? 'connected' : 'disabled',
            reason: $activeCount > 0 ? "{$activeCount} active connection(s)." : 'No MCP connections have been created yet.',
            testable: true,
        );
    }

    private function umamiIntegration(): IntegrationStatus
    {
        if (! $this->umami->enabledSetting()) {
            return new IntegrationStatus(
                key: 'umami',
                label: 'Umami',
                state: 'disabled',
                reason: null,
                testable: true,
            );
        }

        if ($this->umami->enabled()) {
            return new IntegrationStatus(
                key: 'umami',
                label: 'Umami',
                state: 'connected',
                reason: 'Analytics script is active on '.$this->umami->allowedOrigin().'.',
                testable: true,
            );
        }

        return new IntegrationStatus(
            key: 'umami',
            label: 'Umami',
            state: 'misconfigured',
            reason: 'Enabled, but the script URL is not a valid HTTPS URL, or the website id is missing.',
            testable: true,
        );
    }
}
