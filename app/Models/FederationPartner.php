<?php

namespace App\Models;

use App\Enums\AcceptancePolicy;
use App\Enums\FieldGroup;
use App\Enums\ImportTypes;
use App\Enums\SharingScope;
use App\Enums\TrustLevel;
use Carbon\CarbonInterface;
use Database\Factories\FederationPartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property Carbon|null $request_sent_at
 * @property string|null $request_message
 * @property string|null $request_contact_email
 * @property Carbon|null $requested_at
 * @property string|null $push_callback_url
 * @property Carbon|null $push_callback_registered_at
 * @property Carbon|null $push_last_delivered_at
 * @property Carbon|null $push_last_failed_at
 * @property Carbon|null $push_subscribed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'domain', 'node_name', 'node_uuid', 'keys_json', 'keys_fetched_at', 'pinned_key_id',
    'trust_level', 'field_groups', 'approved_by_user_id', 'last_ok_at',
    'consecutive_failures', 'last_synced_at', 'last_attempted_at',
    'acceptance_policy', 'sharing_scope', 'import_types',
    'request_sent_at', 'request_message', 'request_contact_email', 'requested_at',
    'push_callback_url', 'push_callback_registered_at', 'push_subscribed_at',
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
            'request_sent_at' => 'datetime',
            'requested_at' => 'datetime',
            'push_callback_registered_at' => 'datetime',
            'push_last_delivered_at' => 'datetime',
            'push_last_failed_at' => 'datetime',
            'push_subscribed_at' => 'datetime',
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
     * Whether this partner subscribed to this node's changes: it holds a
     * registered callback, and a verified partnership — the same bar the
     * feed itself applies (FP-13), so a partner blocked or downgraded
     * after subscribing stops receiving pushes at once.
     *
     * // api-design.md §Subscriptions (API-10)
     */
    public function receivesPushes(): bool
    {
        return $this->push_callback_url !== null && $this->trust_level === TrustLevel::Verified;
    }

    /**
     * Whether this node subscribed to the partner's changes — its pushes
     * are accepted at the inbox and its reconciliation poll is daily.
     *
     * // api-design.md §Subscriptions (API-11)
     */
    public function isPushSubscribed(): bool
    {
        return $this->push_subscribed_at !== null;
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
     * A partner can be removed outright only while nothing has been
     * received from it; after that its row anchors every copy's
     * provenance (ID-3) and blocking is the only way to end the
     * partnership.
     */
    public function isRemovable(): bool
    {
        return $this->listingCopies()->doesntExist();
    }

    /**
     * A partner unreachable beyond the staleness threshold marks all its
     * copies stale in any consuming UI (FP-15).
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isStale(): bool
    {
        return $this->unreachableSince()->lt($this->thresholdFor('flag_after_days'));
    }

    /**
     * A partner unreachable far beyond that threshold has its copies
     * withheld from public display — the data API and everything built on
     * it — while staying visible, and marked, to operators.
     *
     * This is the only thing that ever drops a vanished authority's
     * listings: it cannot send a tombstone once it stops answering, so
     * without this they would be served as current indefinitely.
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isHidden(): bool
    {
        return $this->unreachableSince()->lt($this->thresholdFor('hide_after_days'));
    }

    /**
     * The SQL mirror of isHidden(), for filtering copies out of public
     * queries. The two MUST stay mirrored: the predicate answers for one
     * partner, the scope constrains a query, and nothing else decides
     * whether a partner's copies may be shown publicly.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePubliclyDisplayable(Builder $query): Builder
    {
        return $query->whereRaw(
            'COALESCE(last_ok_at, created_at) >= ?',
            [$this->thresholdFor('hide_after_days')->toDateTimeString()],
        );
    }

    /**
     * The instant this partner was last known reachable. A partnership
     * that has never once synced successfully falls back to when it was
     * added — otherwise it would never age, and "never worked at all" is
     * precisely the case an operator needs told about.
     */
    private function unreachableSince(): CarbonInterface
    {
        return $this->last_ok_at ?? $this->created_at ?? Carbon::now();
    }

    private function thresholdFor(string $key): CarbonInterface
    {
        return Carbon::now()->subDays((int) config("openyacht.staleness.{$key}"));
    }
}
