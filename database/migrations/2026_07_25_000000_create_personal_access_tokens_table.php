<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Sanctum's standard personal_access_tokens table, reproduced
 * verbatim from laravel/sanctum's own migration (vendor/laravel/sanctum/
 * database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php)
 * rather than published/loaded from the package — Sanctum does not
 * auto-load its migrations, so this repo owns a normal, dated migration
 * file like every other table. `abilities` is where Task 17's ApiScope
 * values are stored (Sanctum's own `tokenCan()`/`Attempt::create()`
 * plumbing reads/writes this column); CraftKeeper never adds columns of
 * its own here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
