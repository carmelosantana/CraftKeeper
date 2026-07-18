<?php

namespace App\Http\Controllers;

use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\PluginSource;
use App\Catalog\Sources\CraftKeeperCatalogSource;
use App\Catalog\Sources\HangarSource;
use App\Catalog\Sources\ModrinthSource;
use App\Support\IntegrationHealthChecker;
use App\Support\IntegrationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Task 19's Integrations overview: GET /integrations shows all ten
 * integrations (Minecraft directory, RCON, AI, CraftKeeper Catalog,
 * Hangar, Modrinth, official documentation cache, API, MCP, Umami) with a
 * Connected/Disabled/Degraded/Misconfigured state each — see
 * App\Support\IntegrationHealthChecker, the single place that computation
 * lives (also reused by App\Support\SupportBundleService's `health.json`,
 * so this page and a generated support bundle can never disagree).
 *
 * POST /integrations/test/{key} is the "actionable test" every row gets:
 * for the network-backed integrations (RCON, the three catalog sources) it
 * performs a REAL, on-demand, bounded live probe and persists the result
 * through the SAME recording paths the passive background health checks
 * use (App\Console\Commands\SampleServerState, App\Catalog\Sources\
 * AbstractPluginSource::search()'s recordSuccess()/recordFailure()) — it
 * does not invent a parallel health-tracking mechanism. For AI, `test`
 * just re-renders (AiManager::healthDetail() always performs a fresh,
 * bounded check itself — see its own docblock). For the remaining
 * integrations (Minecraft directory, documentation, API, MCP, Umami)
 * there is no live network probe to make — "test" simply recomputes and
 * re-displays the current, honest state, which is already exactly what a
 * page reload would show.
 */
class IntegrationController extends Controller
{
    /**
     * @var list<string>
     */
    private const TESTABLE_KEYS = [
        'minecraft-directory', 'rcon', 'ai', 'catalog', 'hangar', 'modrinth',
        'documentation', 'api', 'mcp', 'umami',
    ];

    public function __construct(
        private readonly IntegrationHealthChecker $health,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Integrations', [
            'integrations' => array_map(
                fn (IntegrationStatus $status) => $status->toArray(),
                $this->health->snapshot(),
            ),
        ]);
    }

    public function test(string $key): RedirectResponse
    {
        if (! in_array($key, self::TESTABLE_KEYS, true)) {
            throw new NotFoundHttpException("Unknown integration \"{$key}\".");
        }

        match ($key) {
            'rcon' => Artisan::call('server:sample-state'),
            'catalog' => $this->testCatalogSource(app(CraftKeeperCatalogSource::class)),
            'hangar' => $this->testCatalogSource(app(HangarSource::class)),
            'modrinth' => $this->testCatalogSource(app(ModrinthSource::class)),
            // AI's health check runs fresh on every read (AiManager::
            // healthDetail()); the rest have no live probe to trigger —
            // the redirect below already re-renders their current,
            // honestly-computed state.
            default => null,
        };

        Inertia::flash('toast', ['type' => 'info', 'message' => "Tested {$key}."]);

        return redirect('/integrations');
    }

    /**
     * search() never throws (see App\Catalog\Sources\AbstractPluginSource's
     * own docblock) — it always records success/failure through
     * App\Catalog\CatalogSourceHealth itself, which is exactly the state
     * the next index() render reads back.
     */
    private function testCatalogSource(PluginSource $source): void
    {
        $source->search(new PluginSearchQuery);
    }
}
