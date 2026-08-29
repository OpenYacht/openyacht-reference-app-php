<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ListingStatus;
use App\Models\CharterYacht;
use App\Models\SaleYacht;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The shared filter set of the listing index pages (own, imported,
 * synced). Parsing lives here; each controller applies the values to its
 * own data shape (columns, relations, or copy payload JSON).
 */
trait FiltersListings
{
    /**
     * @return array{q: string, location: string, category: string, loa_min: float|null, loa_max: float|null, builder: string, year_min: int|null, year_max: int|null, power_sail: string, status: string}
     */
    private function listingFilters(Request $request): array
    {
        $number = function (string $key) use ($request): ?float {
            $value = $request->query($key);

            return is_numeric($value) ? (float) $value : null;
        };

        $year = function (string $key) use ($number): ?int {
            $value = $number($key);

            return $value === null ? null : (int) $value;
        };

        $powerSail = trim((string) $request->query('power_sail'));

        return [
            'q' => trim((string) $request->query('q')),
            'location' => trim((string) $request->query('location')),
            'category' => trim((string) $request->query('category')),
            'loa_min' => $number('loa_min'),
            'loa_max' => $number('loa_max'),
            'builder' => trim((string) $request->query('builder')),
            'year_min' => $year('year_min'),
            'year_max' => $year('year_max'),
            'power_sail' => in_array($powerSail, ['power', 'sail'], true) ? $powerSail : '',
            'status' => ListingStatus::tryFrom(trim((string) $request->query('status')))->value ?? '',
        ];
    }

    /**
     * Apply the own-listing filter set to a sale or charter query — the
     * two tables share every filtered column and relation, so one chain
     * serves both (and the partner shared-listings picker). Broker
     * scoping and ordering stay with each caller.
     *
     * @template TListing of SaleYacht|CharterYacht
     *
     * @param  EloquentBuilder<TListing>  $query
     * @param  array{q: string, location: string, category: string, loa_min: float|null, loa_max: float|null, builder: string, year_min: int|null, year_max: int|null, power_sail: string, status: string}  $filters
     * @return EloquentBuilder<TListing>
     */
    private function applyOwnListingFilters(EloquentBuilder $query, array $filters): EloquentBuilder
    {
        return $query
            ->when($filters['q'] !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name', 'like', "%{$filters['q']}%")
                    ->orWhereHas('vessel', fn ($vessel) => $vessel
                        ->where('builder_name', 'like', "%{$filters['q']}%")
                        ->orWhere('model_name', 'like', "%{$filters['q']}%")),
            ))
            ->when($filters['location'] !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('location_display', 'like', "%{$filters['location']}%")
                    ->orWhere('location_city', 'like', "%{$filters['location']}%")
                    ->orWhere('location_country', 'like', "%{$filters['location']}%")
                    ->orWhere('location_marina', 'like', "%{$filters['location']}%"),
            ))
            ->when($filters['category'] !== '', fn ($query) => $query
                ->where('specifications->category->slug', $filters['category']))
            ->when($filters['loa_min'] !== null, fn ($query) => $query
                ->whereHas('vessel', fn ($vessel) => $vessel->where('loa_m', '>=', $filters['loa_min'])))
            ->when($filters['loa_max'] !== null, fn ($query) => $query
                ->whereHas('vessel', fn ($vessel) => $vessel->where('loa_m', '<=', $filters['loa_max'])))
            ->when($filters['builder'] !== '', fn ($query) => $query
                ->whereHas('vessel', fn ($vessel) => $vessel->where('builder_name', $filters['builder'])))
            ->when($filters['year_min'] !== null, fn ($query) => $query
                ->whereHas('vessel', fn ($vessel) => $vessel->where('year_built', '>=', $filters['year_min'])))
            ->when($filters['year_max'] !== null, fn ($query) => $query
                ->whereHas('vessel', fn ($vessel) => $vessel->where('year_built', '<=', $filters['year_max'])))
            // power_or_sail is a string in the specifications JSON — a
            // plain JSON where compares correctly on every engine (the
            // ? * 1 helper is for numeric paths only).
            ->when($filters['power_sail'] !== '', fn ($query) => $query
                ->where('specifications->power_or_sail', $filters['power_sail']))
            ->when($filters['status'] !== '', fn ($query) => $query
                ->where('status', $filters['status']));
    }

    /**
     * The distinct builder names of the fleet, for the filter bar's
     * builder select — exact names, so free-text builders (no registry
     * slug) are filterable too.
     *
     * @return Collection<int, string>
     */
    private function builderOptions(): Collection
    {
        return Vessel::query()
            ->whereNotNull('builder_name')
            ->distinct()
            ->orderBy('builder_name')
            ->pluck('builder_name');
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    private function statusOptions(): Collection
    {
        return collect(ListingStatus::cases())
            ->map(fn (ListingStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ]);
    }

    /**
     * Numeric comparison on a JSON path, identical on SQLite, MySQL, and
     * MariaDB. Never use `->where('col->path', $float)` for this: PDO binds
     * PHP floats as strings, and SQLite refuses to coerce TEXT against the
     * REAL that json_extract() returns, so the comparison silently matches
     * nothing on SQLite while working on MySQL. Binding through `? * 1`
     * restores numeric affinity on all three engines.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    private function whereJsonNumeric(EloquentBuilder|QueryBuilder $query, string $column, string $operator, float|int $value): void
    {
        if (! in_array($operator, ['=', '<', '<=', '>', '>='], true)) {
            throw new InvalidArgumentException("Unsupported operator [{$operator}].");
        }

        $segments = explode('->', $column);
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $field = $base->getGrammar()->wrap(array_shift($segments));
        $jsonPath = '$.'.implode('.', $segments);

        // Not a literal string, but injection-safe: $operator is whitelisted
        // above, $field goes through the grammar's identifier wrapping, and
        // user input travels only in the bindings.
        // @phpstan-ignore-next-line
        $query->whereRaw("json_extract({$field}, ?) {$operator} ? * 1", [$jsonPath, $value]);
    }
}
