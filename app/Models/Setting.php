<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A plain key/value store for non-sensitive application configuration
 * (e.g. the Minecraft server directory, RCON host/port, analytics opt-in).
 * Anything sensitive (passwords, API keys) belongs in {@see Secret}
 * instead — `value` here is stored and returned as plain text.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Fetch a setting's value by key, or a default if it isn't set.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    /**
     * Create or update a setting's value.
     */
    public static function put(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
