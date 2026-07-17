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

];
