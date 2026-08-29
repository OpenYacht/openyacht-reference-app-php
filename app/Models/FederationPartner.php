<?php

namespace App\Models;

use App\Enums\AcceptancePolicy;
use App\Enums\FieldGroup;
use App\Enums\ImportTypes;
use App\Enums\SharingScope;
use App\Enums\TrustLevel;
use Database\Factories\FederationPartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A federation partner: another OpenYacht node this node exchanges
 * listings with. Identity is the domain; the node UUID exists to detect
 * that a domain now hosts a different installation.
 *
 * // federation-protocol.md §Partner Lifecycle
 *
 * @property int $id
 * @property string $domain
 * @property string|null $node_name
 * @property string|null $node_uuid
 * @property array<int, array<string, mixed>>|null $keys_json
 * @property Carbon|null $keys_fetched_at
 * @property string|null $pinned_key_id
 * @property TrustLevel $trust_level
 * @property array<int, string>|null $field_groups
 * @property AcceptancePolicy $acceptance_policy
 * @property SharingScope $sharing_scope
 * @property ImportTypes $import_types
 * @property int|null $approved_by_user_id
 * @property Carbon|null $last_ok_at
 * @property int $consecutive_failures
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'domain', 'node_name', 'node_uuid', 'keys_json', 'keys_fetched_at', 'pinned_key_id',
    'trust_level', 'field_groups', 'approved_by_user_id', 'last_ok_at',
    'consecutive_failures', 'last_synced_at', 'last_attempted_at',
    'acceptance_policy', 'sharing_scope', 'import_types',
])]
class FederationPartner extends Model
{
    /** @use HasFactory<FederationPartnerFactory> */
    use HasFactory;

    /**
     * The attribute-level twins of the column defaults: every partner
     * holds listings for review until an operator loosens the policy, and
     * receives the open catalogue until an operator curates it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'acceptance_policy' => 'review',
        'sharing_scope' => 'standard',
        'import_types' => 'both',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keys_json' => 'array',
            'keys_fetched_at' => 'datetime',
            'trust_level' => TrustLevel::class,
            'field_groups' => 'array',
            'acceptance_policy' => AcceptancePolicy::class,
            'sharing_scope' => SharingScope::class,
            'import_types' => ImportTypes::class,
            'last_ok_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<ListingCopy, $this>
     */
    public function listingCopies(): HasMany
    {
        return $this->hasMany(ListingCopy::class);
    }

    /**
     * @return BelongsToMany<PartnerGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(PartnerGroup::class, 'partner_group_members');
    }

    /**
     * The acceptance policy that actually applies: the most permissive of
     * the partner's own setting and its groups'. Group membership is the
     * grant — putting a partner in a "trusted" group is what loosens its
     * policy, and removing it is what revokes it; an individual setting
     * can therefore not be stricter than a group the partner belongs to.
     */
    public function effectiveAcceptancePolicy(): AcceptancePolicy
    {
        return AcceptancePolicy::mostPermissive([
            $this->acceptance_policy,
            ...$this->groups()->whereNotNull('acceptance_policy')->get()
                ->map(fn (PartnerGroup $group): AcceptancePolicy => $group->acceptance_policy),
        ]);
    }

    /**
     * Whether this node projects the partner's listings of the given
     * wire type ('sale' or 'charter') for display. Copies are always
     * stored regardless — this gates only projection and the review
     * queue.
     */
    public function importsType(string $type): bool
    {
        return $this->import_types->accepts($type);
    }

    /**
     * The partner's published keys as key_id => base64 public key, the
     * shape the Verifier consumes.
     *
     * @return array<string, string>
     */
    public function publishedKeys(): array
    {
        return collect($this->keys_json ?? [])
            ->mapWithKeys(fn (array $key): array => [$key['key_id'] => $key['public_key']])
            ->all();
    }

    /**
     * The key id the partner currently signs with. The ecosystem
     * convention lists the current key first in the well-known document;
     * a strictly newer created_at elsewhere in the list wins as a
     * fallback for nodes that order differently.
     *
     * // federation-protocol.md §Key Rotation
     */
    public function currentSigningKeyId(): ?string
    {
        $keys = collect($this->keys_json ?? []);

        if ($keys->isEmpty()) {
            return null;
        }

        $conventional = $keys->first();
        $newest = $keys->sortByDesc(fn (array $key): string => (string) ($key['created_at'] ?? ''))->first();

        return (string) ($newest['created_at'] ?? '') > (string) ($conventional['created_at'] ?? '')
            ? ($newest['key_id'] ?? null)
            : ($conventional['key_id'] ?? null);
    }

    /**
     * The field groups granted to this partner (LS-14). A null column
     * means every group is granted; an explicit array restricts to those
     * groups.
     *
     * @return array<int, FieldGroup>
     */
    public function grantedFieldGroups(): array
    {
        if ($this->field_groups === null) {
            return FieldGroup::cases();
        }

        return collect($this->field_groups)
            ->map(fn ($value) => FieldGroup::tryFrom((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    public function hasFieldGroup(FieldGroup $group): bool
    {
        return in_array($group, $this->grantedFieldGroups(), true);
    }

    /**
     * A partner unreachable beyond the staleness threshold marks all its
     * copies stale in any consuming UI (FP-15).
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isStale(): bool
    {
        return $this->last_ok_at !== null
            && $this->last_ok_at->lt(now()->subDays(7));
    }
}
