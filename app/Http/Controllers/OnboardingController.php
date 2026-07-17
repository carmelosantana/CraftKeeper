<?php

namespace App\Http\Controllers;

use App\Models\Secret;
use App\Models\Setting;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Drives CraftKeeper's first-run setup wizard: Welcome -> admin account ->
 * Minecraft directory check -> RCON setup/test -> optional AI provider ->
 * optional analytics -> completion.
 *
 * Only the admin-account step is functional in this task. The Minecraft
 * directory / RCON / AI / analytics steps collect and persist values (so
 * later tasks have something to build on) but do not perform any live
 * filesystem, RCON, AI, or analytics calls yet — those are wired in Tasks
 * 10, 16, and 19 respectively. Every step after the mandatory admin
 * account can be skipped and revisited; nothing here is a security
 * boundary except the admin-creation step itself, which `RequireInstallation`
 * permanently removes (404, not a redirect) the instant an admin exists.
 */
class OnboardingController extends Controller
{
    /**
     * GET /onboarding — the welcome step and (client-side) admin-account
     * form. Only reachable before an admin exists.
     */
    public function welcome(): Response
    {
        return Inertia::render('onboarding/Index', [
            'step' => 'welcome',
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * POST /onboarding/admin — create the single CraftKeeper administrator
     * and sign them in immediately.
     *
     * There is no mail server in V1, so a self-hosted single admin can
     * never receive a verification email — the account is created
     * pre-verified so onboarding never locks the operator out of their own
     * install. `RequireInstallation:not-installed` on this route means a
     * second call here — replayed, guessed, or otherwise — 404s rather
     * than creating a second admin: `InstallationState::isInstalled()`
     * simply checks whether any user row exists, so this is enforced by
     * data, not by a flag that could drift out of sync.
     */
    public function storeAdmin(Request $request, CreatesNewUsers $creator): RedirectResponse
    {
        $user = $creator->create($request->all());

        $user->forceFill(['email_verified_at' => now()])->save();

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect('/onboarding/server');
    }

    /**
     * GET /onboarding/server — Minecraft directory step.
     */
    public function server(): Response
    {
        return Inertia::render('onboarding/Index', [
            'step' => 'server',
            'minecraftPath' => Setting::get('minecraft.server_path'),
        ]);
    }

    /**
     * POST /onboarding/server — save the configured Minecraft directory.
     * Not validated against the filesystem here (no live check yet — see
     * class docblock); the operator can revisit this later once real
     * scanning lands.
     */
    public function storeServer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'minecraft_path' => ['nullable', 'string', 'max:1024'],
        ]);

        Setting::put('minecraft.server_path', $validated['minecraft_path'] ?? null);

        return redirect('/onboarding/rcon');
    }

    /**
     * GET /onboarding/rcon — RCON setup/test step.
     */
    public function rcon(): Response
    {
        return Inertia::render('onboarding/Index', [
            'step' => 'rcon',
            'rconHost' => Setting::get('rcon.host'),
            'rconPort' => Setting::get('rcon.port'),
            // Never the password itself — only whether one is on file.
            'rconPasswordConfigured' => Secret::configured('rcon.password'),
        ]);
    }

    /**
     * POST /onboarding/rcon — save RCON connection details. The host/port
     * are plain `Setting`s; the password is a `Secret` (encrypted at
     * rest, never re-rendered). No live connection test is performed yet
     * (Task 10) — the "Test connection" control in the UI is a labeled
     * placeholder.
     */
    public function storeRcon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rcon_host' => ['nullable', 'string', 'max:255'],
            'rcon_port' => ['nullable', 'integer', 'between:1,65535'],
            'rcon_password' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::put('rcon.host', $validated['rcon_host'] ?? null);
        Setting::put('rcon.port', isset($validated['rcon_port']) ? (string) $validated['rcon_port'] : null);

        if (filled($validated['rcon_password'] ?? null)) {
            Secret::put('rcon.password', $validated['rcon_password']);
        }

        return redirect('/onboarding/ai');
    }

    /**
     * GET /onboarding/ai — optional AI provider step.
     */
    public function ai(): Response
    {
        return Inertia::render('onboarding/Index', [
            'step' => 'ai',
            'aiProvider' => Setting::get('ai.provider'),
            'aiApiKeyConfigured' => Secret::configured('ai.api_key'),
        ]);
    }

    /**
     * POST /onboarding/ai — optionally save an AI provider + API key.
     * Real wiring lands in Task 16; this only persists the values.
     */
    public function storeAi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_provider' => ['nullable', 'string', 'max:100'],
            'ai_api_key' => ['nullable', 'string', 'max:1024'],
        ]);

        if (filled($validated['ai_provider'] ?? null)) {
            Setting::put('ai.provider', $validated['ai_provider']);
        }

        if (filled($validated['ai_api_key'] ?? null)) {
            Secret::put('ai.api_key', $validated['ai_api_key']);
        }

        return redirect('/onboarding/analytics');
    }

    /**
     * GET /onboarding/analytics — optional analytics opt-in step.
     */
    public function analytics(): Response
    {
        return Inertia::render('onboarding/Index', [
            'step' => 'analytics',
            'analyticsEnabled' => Setting::get('analytics.enabled') === '1',
        ]);
    }

    /**
     * POST /onboarding/analytics — optionally opt in to analytics. Real
     * wiring lands in Task 19; this only persists the choice.
     */
    public function storeAnalytics(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'analytics_enabled' => ['nullable', 'boolean'],
        ]);

        Setting::put('analytics.enabled', ($validated['analytics_enabled'] ?? false) ? '1' : '0');

        return redirect('/onboarding/complete');
    }

    /**
     * GET /onboarding/complete — setup finished.
     */
    public function complete(): Response
    {
        Setting::put('onboarding.completed_at', now()->toIso8601String());

        return Inertia::render('onboarding/Index', [
            'step' => 'complete',
        ]);
    }
}
