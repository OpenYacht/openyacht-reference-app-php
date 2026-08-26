<?php

namespace App\Services\Federation;

use App\Enums\ListingStatus;
use App\Models\CharterYacht;
use App\Models\ListingCopy;
use App\Models\SaleYacht;
use Illuminate\Support\Collection;

/**
 * Vessel-identity matching for incoming partner listings. An exact HIN
 * or IMO match against another live listing from a different authority
 * is a hard match: both records are retained, the conflict is flagged
 * for human review, and software never resolves it (ID-9 — usually a
 * mandate handover in progress or a genuine dispute, and code
 * adjudicating it would be wrong half the time). Absent hard
 * identifiers, builder + model + year_built + loa_m within tolerance is
 * a candidate match requiring human confirmation, never automation —
 * checked against this node's own listings, the case where
 * auto-publishing a duplicate hurts most (the partner's copy of a
 * vessel this node holds the mandate on, beside its own listing).
 *
 * // yacht-identity.md §Vessel Identity, §Central agency changes
 */
class VesselIdentityMatcher
{
    private const LOA_TOLERANCE_M = 0.5;

    /**
     * The conflicts an incoming copy raises, ready for the copy's
     * identity_conflicts column. Empty when nothing matches.
     *
     * @return list<array{with: string, matched_on: string, label: string, uuid: string|null}>
     */
    public function conflictsFor(ListingCopy $copy): array
    {
        $vessel = data_get($copy->payload, 'vessel', []);
        $vessel = is_array($vessel) ? $vessel : [];
        $conflicts = [];

        foreach ($this->ownListings() as $own) {
            foreach (['hin', 'imo'] as $identifier) {
                if ($this->identifiersMatch($vessel[$identifier] ?? null, $own->vessel->{$identifier})) {
                    $conflicts[] = [
                        'with' => 'own',
                        'matched_on' => $identifier,
                        'label' => $own->name,
                        'uuid' => $own->uuid,
                    ];

                    continue 2;
                }
            }

            if ($this->isSoftCandidate($vessel, $own)) {
                $conflicts[] = [
                    'with' => 'own',
                    'matched_on' => 'vessel_profile',
                    'label' => $own->name,
                    'uuid' => $own->uuid,
                ];
            }
        }

        foreach ($this->liveCopiesFromOtherAuthorities($copy) as $other) {
            foreach (['hin', 'imo'] as $identifier) {
                if ($this->identifiersMatch($vessel[$identifier] ?? null, data_get($other->payload, "vessel.{$identifier}"))) {
                    $conflicts[] = [
                        'with' => 'partner',
                        'matched_on' => $identifier,
                        'label' => "{$other->name} ({$other->authority_domain})",
                        'uuid' => null,
                    ];

                    continue 2;
                }
            }
        }

        return $conflicts;
    }

    private function identifiersMatch(mixed $incoming, mixed $held): bool
    {
        return is_string($incoming) && trim($incoming) !== ''
            && is_string($held) && trim($held) !== ''
            && strcasecmp(trim($incoming), trim($held)) === 0;
    }

    /**
     * The soft candidate: all four of builder, model, year, and LOA
     * present on both sides, names equal, year exact, LOA within
     * tolerance (yacht-identity.md — names are the weakest signal and
     * play no part).
     *
     * @param  array<string, mixed>  $vessel
     */
    private function isSoftCandidate(array $vessel, SaleYacht|CharterYacht $own): bool
    {
        $builder = data_get($vessel, 'builder.name');
        $model = data_get($vessel, 'model.name');
        $year = data_get($vessel, 'year_built');
        $loa = data_get($vessel, 'loa_m');

        return is_string($builder) && is_string($own->vessel->builder_name)
            && strcasecmp(trim($builder), trim($own->vessel->builder_name)) === 0
            && is_string($model) && is_string($own->vessel->model_name)
            && strcasecmp(trim($model), trim($own->vessel->model_name)) === 0
            && is_numeric($year) && $own->vessel->year_built !== null
            && (int) $year === $own->vessel->year_built
            && is_numeric($loa) && $own->vessel->loa_m !== null
            && abs((float) $loa - $own->vessel->loa_m) <= self::LOA_TOLERANCE_M;
    }

    /**
     * This node's live listings — the ones a duplicate would sit beside.
     * Under-offer listings are still active mandates.
     *
     * @return Collection<int, SaleYacht|CharterYacht>
     */
    private function ownListings()
    {
        $live = [ListingStatus::Active, ListingStatus::UnderOffer];

        return SaleYacht::query()->with('vessel')->whereIn('status', $live)->get()
            ->concat(CharterYacht::query()->with('vessel')->whereIn('status', $live)->get());
    }

    /**
     * Live copies held from other authorities. Two listings from the
     * SAME authority are that authority's own affair, not a conflict.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ListingCopy>
     */
    private function liveCopiesFromOtherAuthorities(ListingCopy $copy)
    {
        return ListingCopy::query()
            ->whereNull('tombstoned_at')
            ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
            ->where('authority_domain', '!=', $copy->authority_domain)
            ->whereKeyNot($copy->id)
            ->get();
    }
}
