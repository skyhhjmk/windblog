<?php

namespace app\util;

use Illuminate\Database\Eloquent\Builder;

/**
 * QueryHelper provides reusable methods for building safe query clauses.
 */
class QueryHelper
{
    /**
     * Adds a case‑insensitive LIKE clause using a bound parameter.
     *
     * Example: QueryHelper::likeInsensitive($q, 'title', $keyword);
     *
     * @param Builder $query  The query builder instance.
     * @param string  $column The column name to apply the LIKE on.
     * @param string  $value  The raw search value (will be escaped).
     */
    public static function likeInsensitive(Builder $query, string $column, string $value): void
    {
        // Escape wildcard characters to avoid unintended matches.
        $escaped = addcslashes(mb_strtolower($value), '%_\\');
        $pattern = "%{$escaped}%";
        // Use a raw clause with a placeholder – safe against injection.
        $query->whereRaw("LOWER({$column}) LIKE ?", [$pattern]);
    }
}
