<?php

namespace App\Http\Controllers;

use App\Models\McpGrant;
use App\Models\Secret;
use App\Models\Setting;
use App\Support\SupportBundleService;
use App\Support\UmamiScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Task 19's Settings index + the four sections that had no page at all
 * before this task (General/Server, AI Providers, Analytics, Advanced).
 * Security, Appearance, Profile (App\Http\Controllers\Settings\*), and
 * the API/MCP integration pages (App\Http\Controllers\Integrations\*)
 * already existed (Tasks 3, 4, 17, 18) and are only LINKED from here, via
 * index() — never duplicated. Backups get their own
 * App\Http\Controllers\BackupController (heavier, file-producing logic
 * than a plain settings form).
 *
 * `updateServer()`/`updateAi()` are validated BY HAND, exactly like
 * App\Http\Controllers\OnboardingController::storeRcon()/storeAi() (which
 * these pages let an operator revisit) — for the identical reason: a
 * failed validation must never flash a plaintext RCON password or AI API
 * key into the session's old-input store, which `$request->validate()`'s
 * default exception handling would otherwise do.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings', [
            'sections' => [
                ['key' => 'server', 'label' => 'General / Server', 'description' => 'Minecraft directory and RCON connection.', 'href' => '/settings/server'],
                ['key' => 'security', 'label' => 'Security', 'description' => 'Password and two-factor authentication.', 'href' => '/settings/security'],
                ['key' => 'ai', 'label' => 'AI Providers', 'description' => 'Optional AI assistant provider and credentials.', 'href' => '/settings/ai'],
                ['key' => 'appearance', 'label' => 'Appearance', 'description' => 'Theme and display preferences.', 'href' => '/settings/appearance'],
                ['key' => 'analytics', 'label' => 'Analytics', 'description' => 'Optional Umami analytics tag.', 'href' => '/settings/analytics'],
                ['key' => 'backups', 'label' => 'Backups', 'description' => 'Create and download application-state backups.', 'href' => '/settings/backups'],
                ['key' => 'api', 'label' => 'API', 'description' => 'Scoped /api/v1 tokens.', 'href' => '/integrations/api'],
                ['key' => 'mcp', 'label' => 'MCP', 'description' => 'OAuth-authorized MCP client connections.', 'href' => '/integrations/mcp'],
                ['key' => 'advanced', 'label' => 'Advanced', 'description' => 'Diagnostics and support bundle export.', 'href' => '/settings/advanced'],
            ],
            'summary' => [
                'aiConfigured' => filled(Setting::get('ai.provider')),
                'analyticsActive' => app(UmamiScript::class)->enabled(),
                'apiTokenCount' => PersonalAccessToken::query()->count(),
                'mcpGrantCount' => McpGrant::query()->whereNull('revoked_at')->count(),
            ],
        ]);
    }

    public function server(): Response
    {
        return Inertia::render('settings/server', [
            'minecraftPath' => Setting::get('minecraft.server_path'),
            'rconHost' => Setting::get('rcon.host'),
            'rconPort' => Setting::get('rcon.port'),
            'rconPasswordConfigured' => Secret::configured('rcon.password'),
        ]);
    }

    public function updateServer(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'minecraft_path' => ['nullable', 'string', 'max:1024'],
            'rcon_host' => ['nullable', 'string', 'max:255'],
            'rcon_port' => ['nullable', 'integer', 'between:1,65535'],
            'rcon_password' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->exceptInput('rcon_password');
        }

        $validated = $validator->validated();

        Setting::put('minecraft.server_path', $validated['minecraft_path'] ?? null);
        Setting::put('rcon.host', $validated['rcon_host'] ?? null);
        Setting::put('rcon.port', isset($validated['rcon_port']) ? (string) $validated['rcon_port'] : null);

        if (filled($validated['rcon_password'] ?? null)) {
            Secret::put('rcon.password', $validated['rcon_password']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Server settings updated.']);

        return redirect('/settings/server');
    }

    public function ai(): Response
    {
        return Inertia::render('settings/ai', [
            'provider' => Setting::get('ai.provider'),
            'hostedBaseUrl' => Setting::get('ai.hosted.base_url'),
            'hostedModel' => Setting::get('ai.hosted.model'),
            'hostedApiKeyConfigured' => Secret::configured('ai.api_key'),
            'ollamaBaseUrl' => Setting::get('ai.ollama.base_url'),
            'ollamaModel' => Setting::get('ai.ollama.model'),
            'ollamaAllowUnredacted' => Setting::get('ai.ollama.allow_unredacted') === '1',
        ]);
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['nullable', 'string', 'max:100'],
            'hosted_base_url' => ['nullable', 'string', 'max:2048'],
            'hosted_model' => ['nullable', 'string', 'max:255'],
            'hosted_api_key' => ['nullable', 'string', 'max:1024'],
            'ollama_base_url' => ['nullable', 'string', 'max:2048'],
            'ollama_model' => ['nullable', 'string', 'max:255'],
            'ollama_allow_unredacted' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->exceptInput('hosted_api_key');
        }

        $validated = $validator->validated();

        Setting::put('ai.provider', $validated['provider'] ?? null);
        Setting::put('ai.hosted.base_url', $validated['hosted_base_url'] ?? null);
        Setting::put('ai.hosted.model', $validated['hosted_model'] ?? null);
        Setting::put('ai.ollama.base_url', $validated['ollama_base_url'] ?? null);
        Setting::put('ai.ollama.model', $validated['ollama_model'] ?? null);
        Setting::put('ai.ollama.allow_unredacted', ($validated['ollama_allow_unredacted'] ?? false) ? '1' : '0');

        if (filled($validated['hosted_api_key'] ?? null)) {
            Secret::put('ai.api_key', $validated['hosted_api_key']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'AI provider settings updated.']);

        return redirect('/settings/ai');
    }

    public function analytics(): Response
    {
        $umami = app(UmamiScript::class);

        return Inertia::render('settings/analytics', [
            'enabled' => $umami->enabledSetting(),
            'scriptUrl' => Setting::get('analytics.umami.script_url'),
            'websiteId' => Setting::get('analytics.umami.website_id'),
            'active' => $umami->enabled(),
            'allowedOrigin' => $umami->allowedOrigin(),
        ]);
    }

    public function updateAnalytics(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'script_url' => ['nullable', 'string', 'max:2048'],
            'website_id' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::put('analytics.umami.enabled', ($validated['enabled'] ?? false) ? '1' : '0');
        Setting::put('analytics.umami.script_url', $validated['script_url'] ?? null);
        Setting::put('analytics.umami.website_id', $validated['website_id'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Analytics settings updated.']);

        return redirect('/settings/analytics');
    }

    public function advanced(): Response
    {
        return Inertia::render('settings/advanced', [
            'dataRoot' => (string) config('craftkeeper.data_root'),
            'minecraftRoot' => (string) config('craftkeeper.minecraft_root'),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
        ]);
    }

    /**
     * Streams a freshly generated, redacted diagnostics archive
     * (App\Support\SupportBundleService — see that class's docblock for
     * exactly what it excludes) and deletes CraftKeeper's own copy of the
     * generated file immediately after the response finishes sending, so
     * an exported bundle never lingers on disk longer than the download
     * itself takes.
     */
    public function downloadSupportBundle(SupportBundleService $bundles): BinaryFileResponse
    {
        $path = $bundles->create();

        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }
}
