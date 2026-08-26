<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Database\Factories\ListingCopyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A copy of a partner's listing. Copies are never presented as this node's
 * own listings (ID-4), never substantively modified (ID-5), and never
 * re-shared onward (ID-6). The canonical URI is an opaque string (ID-2).
 *
 * // yacht-identity.md §What everyone else holds
 *
 * @property int $id
 * @property int $federation_partner_id
 * @property string $canonical_uri
 * @property string $authority_domain
 * @property string $type
 * @property ListingStatus $status
 * @property string|null $name
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $listing_updated_at
 * @property Carbon $received_at
 * @property bool $signature_verified
 * @property Carbon|null $tombstoned_at
 * @property array<int, array{with: string, matched_on: string, label: string, uuid: string|null}>|null $identity_conflicts
 * @property Carbon|null $conflict_reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'federation_partner_id', 'canonical_uri', 'authority_domain', 'type',
    'status', 'name', 'payload', 'listing_updated_at', 'received_at',
    'signature_verified', 'tombstoned_at', 'identity_conflicts',
    'conflict_reviewed_at',
])]
class ListingCopy extends Model
{
    /** @use HasFactory<ListingCopyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'payload' => 'array',
            'listing_updated_at' => 'datetime',
            'received_at' => 'datetime',
            'signature_verified' => 'boolean',
            'tombstoned_at' => 'datetime',
            'identity_conflicts' => 'array',
            'conflict_reviewed_at' => 'datetime',
        ];
    }

    /**
     * An unreviewed vessel-identity conflict: the flag every auto-publish
     * path must consult before importing (ID-9 — flagged for human
     * review, never auto-resolved).
     */
    public function hasUnreviewedConflict(): bool
    {
        return $this->identity_conflicts !== null
            && $this->identity_conflicts !== []
            && $this->conflict_reviewed_at === null;
    }

    /**
     * @return BelongsTo<FederationPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(FederationPartner::class, 'federation_partner_id');
    }

    /**
     * @return HasOne<ImportedYacht, $this>
     */
    public function import(): HasOne
    {
        return $this->hasOne(ImportedYacht::class);
    }

    /**
     * The mandatory provenance block (ID-3).
     *
     * @return array{canonical: string, authority: string, received_at: string, signature_verified: bool}
     */
    public function provenance(): array
    {
        return [
            'canonical' => $this->canonical_uri,
            'authority' => $this->authority_domain,
            'received_at' => $this->received_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'signature_verified' => $this->signature_verified,
        ];
    }
}
