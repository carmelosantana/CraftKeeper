<?php

namespace App\Support;

use App\Models\User;

/**
 * CraftKeeper is a single-admin, self-hosted application: exactly one user
 * account may ever exist. "Installed" simply means that admin account has
 * been created — there is no separate installation flag to fall out of
 * sync with reality, and no race window where a flag says "installed" but
 * no admin exists (or vice versa).
 */
class InstallationState
{
    /**
     * Whether the single administrator account has been created.
     */
    public static function isInstalled(): bool
    {
        return User::query()->exists();
    }
}
