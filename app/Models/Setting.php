<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable key/value settings persisted across deploys. Values are
 * stored as text; callers cast on read. Reads are rare (a settings page
 * load, the daily prune), so this stays deliberately un-cached.
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = static::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function set(string $key, string|int|null $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value],
        );
    }
}
