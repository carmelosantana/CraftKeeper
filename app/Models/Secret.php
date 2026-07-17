<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A key/value store for sensitive configuration (RCON passwords, AI
 * provider API keys, ...). `value` uses Laravel's `encrypted` cast, so it
 * is only ever stored as ciphertext at rest, and is decrypted transparently
 * when read as a PHP attribute.
 *
 * `value` is ALSO declared `#[Hidden]` so it is stripped from every
 * `toArray()`/`toJson()` call — including Inertia props, API responses,
 * and anything that serializes this model — regardless of whether the
 * calling code remembered to `->makeHidden(...)` it. Callers that
 * genuinely need the decrypted secret (e.g. Task 10's RCON client) must
 * read the `value` attribute explicitly on a model instance; it will
 * never come back from serialization.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
#[Hidden(['value'])]
class Secret extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    /**
     * Fetch a secret's decrypted value by key, or a default if it isn't set.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $secret = static::query()->where('key', $key)->first();

        return $secret instanceof self ? $secret->value : $default;
    }

    /**
     * Whether a secret has been stored for this key (without ever
     * returning or logging its value).
     *
     * Deliberately not named `has()`/`exists()` — those collide with
     * Eloquent's own relation-existence query methods (`Model::has()`) by
     * name, which confuses Larastan's static analysis into treating
     * `Secret::has('rcon.password')` as a dotted relation path lookup.
     */
    public static function configured(string $key): bool
    {
        return static::query()->where('key', $key)->exists();
    }

    /**
     * Create or update a secret's value.
     */
    public static function put(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
