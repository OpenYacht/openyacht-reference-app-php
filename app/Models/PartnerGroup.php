<?php

namespace App\Models;

use Database\Factories\PartnerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of partners — the audience shorthand layer. A listing with
 * a selected audience is visible to the union of its individually
 * selected partners and the members of its selected groups, resolved
 * dynamically: membership changes replay through the visibility-event
 * log (SharingService::replaceGroupMembers) without touching any
 * listing.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
class PartnerGroup extends Model
{
    /** @use HasFactory<PartnerGroupFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * @return BelongsToMany<FederationPartner, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(FederationPartner::class, 'partner_group_members');
    }

    /**
     * The canonical UUIDs of listings whose selected audience includes
     * this group.
     *
     * @return list<string>
     */
    public function listingUuidsSelecting(): array
    {
        return $this->newQuery()
            ->getConnection()
            ->table('listing_audience_groups')
            ->where('partner_group_id', $this->id)
            ->pluck('listing_uuid')
            ->all();
    }
}
