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

];
