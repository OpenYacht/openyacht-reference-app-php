<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An API key for the internal data API. The plaintext key (oy_…) is
 * generated once, shown once, and only its SHA-256 hash is stored.
 *
 * @property int $id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property array<int, string> $scopes
 * @property array<int, string>|null $domains
 * @property int $rate_limit
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'name', 'key_prefix', 'key_hash', 'scopes', 'domains', 'rate_limit',
    'is_active', 'last_used_at', 'created_by_user_id',
])]
class ApiKey extends Model
{
    use LogsActivity;

    /**
     * Available API scopes and their descriptions.
     */
    public const AVAILABLE_SCOPES = [
        'yachts:read' => 'Read the unified yacht feed: own and imported listings in the wire schema shape',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'domains' => 'array',
            'rate_limit' => 'integer',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Generate a new key. Returns the model and the plaintext — the only
     * time the plaintext ever exists outside the caller's hands.
     *
     * @param  array<int, string>  $scopes
     * @param  array<int, string>|null  $domains
     * @return array{key: self, plaintext: string}
     */
    public static function generate(string $name, array $scopes, ?array $domains = null, int $rateLimit = 60, ?User $createdBy = null): array
    {
        $plaintext = 'oy_'.Str::random(40);

        $key = static::create([
            'name' => $name,
            'key_prefix' => substr($plaintext, 0, 12),
            'key_hash' => hash('sha256', $plaintext),
            'scopes' => $scopes,
            'domains' => $domains,
            'rate_limit' => $rateLimit,
            'created_by_user_id' => $createdBy?->id,
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    public static function findByPlaintext(string $plaintext): ?self
    {
        return static::query()
            ->where('key_hash', hash('sha256', $plaintext))
            ->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Update last_used_at, throttled to every five minutes.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinutes(5))) {
            $this->update(['last_used_at' => now()]);
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'scopes', 'domains', 'rate_limit', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "API key {$eventName}");
    }
}
