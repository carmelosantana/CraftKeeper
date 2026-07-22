<?php

namespace App\Http\Middleware;

use App\Server\ServerIdentityService;
use App\Server\ServerStatusService;
use App\Server\ServerVersionDetector;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'shell' => $this->shell($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The real server and account facts resources/js/layouts/AppShell.tsx
     * renders in its identity card and account menu, on every page.
     *
     * This exists because that component previously shipped a hard-coded
     * DEFAULT_SERVER ("Survival", "mc.example.net", "Paper 1.21.4", status
     * online, 3 / 40 players) and DEFAULT_USER ("admin", TOTP on) left over
     * from the design-system mock it was built against — and not one of its
     * 25 call sites ever passed a real value, so every install displayed all
     * of it, on every page, forever. The player count was the visible tell;
     * the TOTP claim was the dangerous one, telling an operator two-factor
     * was on when it may not have been.
     *
     * Shared rather than threaded through those call sites so the shell can
     * never again render without real data: a page that forgets to pass
     * something falls back to "unknown", never to a plausible-looking
     * invention.
     *
     * Null for guests — onboarding and login render no shell at all, and
     * an unauthenticated request should not be probing the Minecraft volume
     * or reading server state.
     *
     * @return array<string, mixed>|null
     */
    private function shell(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $snapshot = app(ServerStatusService::class)->snapshot();
        $identity = app(ServerIdentityService::class)->identity();
        $version = app(ServerVersionDetector::class)->detect();

        return [
            'server' => [
                'name' => $identity->motd,
                'version' => $version->known ? $version->label : null,
                // "RCON is unreachable" is NOT "the server is offline" —
                // CraftKeeper cannot tell those apart, so it says so. The
                // old default claimed `online` unconditionally, complete
                // with a green indicator.
                'status' => $snapshot->rcon->available ? 'online' : 'unknown',
                // Null whenever RCON is unavailable: RconStatus guarantees
                // playerCount is null rather than a fabricated 0 in that
                // case, and that guarantee is passed straight through.
                'playersOnline' => $snapshot->rcon->playerCount,
                'playersMax' => $identity->maxPlayers,
                'playersReason' => $snapshot->rcon->reason,
            ],
            'user' => [
                'name' => $user->name,
                'totpEnabled' => $user->two_factor_confirmed_at !== null,
            ],
        ];
    }
}
