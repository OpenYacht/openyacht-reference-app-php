<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The shared filter set of the listing index pages (own, imported,
 * synced). Parsing lives here; each controller applies the values to its
 * own data shape (columns, relations, or copy payload JSON).
 */
trait FiltersListings
{
    /**
     * @return array{q: string, location: string, category: string, loa_min: float|null, loa_max: float|null}
     */
    private function listingFilters(Request $request): array
    {
        $number = function (string $key) use ($request): ?float {
            $value = $request->query($key);

            return is_numeric($value) ? (float) $value : null;
        };

        return [
            'q' => trim((string) $request->query('q')),
            'location' => trim((string) $request->query('location')),
            'category' => trim((string) $request->query('category')),
            'loa_min' => $number('loa_min'),
            'loa_max' => $number('loa_max'),
        ];
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
