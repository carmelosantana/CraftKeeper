<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on whether CraftKeeper's single admin account has been
 * created yet.
 *
 * `installed` — 404s unless an admin already exists. Use on routes that
 * only make sense once the app is set up.
 *
 * `not-installed` — 404s once an admin exists. Use on the first-run
 * onboarding routes (the welcome screen and the admin-creation endpoint)
 * so they vanish for good the moment installation completes: a 404, not a
 * redirect or a hidden link, so a second `POST /onboarding/admin` can never
 * create a second administrator even if someone replays the request or
 * guesses the URL directly.
 */
class RequireInstallation
{
    public function handle(Request $request, Closure $next, string $when = 'installed'): Response
    {
        $installed = InstallationState::isInstalled();

        $blocked = match ($when) {
            'not-installed' => $installed,
            default => ! $installed,
        };

        if ($blocked) {
            abort(404);
        }

        return $next($request);
    }
}
