<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Root
    |--------------------------------------------------------------------------
    |
    | Absolute path to the writable directory where CraftKeeper stores its
    | own state, such as the SQLite database and future snapshots/audit
    | data. The container sets DATA_ROOT=/data; local development and the
    | test suite fall back to a directory inside Laravel's storage path so
    | neither depends on a path that only exists inside Docker.
    |
    */

    'data_root' => env('DATA_ROOT', storage_path('craftkeeper')),

    /*
    |--------------------------------------------------------------------------
    | Minecraft Root
    |--------------------------------------------------------------------------
    |
    | Absolute path to the mounted Minecraft server directory CraftKeeper is
    | allowed to read and write. This is the one and only root every
    | App\Filesystem\MinecraftPath canonically resolves against — nothing in
    | CraftKeeper may read or write outside of it. The container always sets
    | MINECRAFT_ROOT=/minecraft (see compose.example.yml); local development
    | and the test suite fall back to a directory inside Laravel's storage
    | path so neither depends on a path that only exists inside Docker. The
    | fallback directory is not created automatically — until it (or a real
    | MINECRAFT_ROOT) exists, every filesystem operation safely refuses to
    | resolve any path rather than operate against a phantom root.
    |
    */

    'minecraft_root' => env('MINECRAFT_ROOT', storage_path('craftkeeper/minecraft')),

    /*
    |--------------------------------------------------------------------------
    | E2E Testing Mode
    |--------------------------------------------------------------------------
    |
    | Gates the test-only database reset endpoint (routes/testing.php,
    | App\Http\Controllers\E2eResetController) that the Playwright suite
    | uses to give every e2e spec file its own deterministic, order-
    | independent baseline. This flag alone does not enable the route —
    | routes/testing.php also requires app()->environment(['local',
    | 'testing']), and the controller re-checks BOTH conditions itself and
    | 404s if either fails. Set ONLY by playwright.config.ts's
    | `webServer.env`; it must never appear in .env, .env.example,
    | compose.example.yml, or the Dockerfile. See
    | docs/architecture/decisions.md for the full rationale.
    |
    */

    'e2e_testing' => (bool) env('E2E_TESTING', false),

];
