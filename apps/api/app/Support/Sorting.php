<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Column sorting for backoffice lists: ?sort={key}&order=asc|desc, limited to an allow-list. */
class Sorting
{
    /**
     * Apply the requested sort when its key is allowed. Returns false when nothing was applied,
     * so the caller can fall back to the list's usual order.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, string|Expression>  $allowed  sort key => column (or raw expression)
     */
    public static function apply(Builder $query, Request $request, array $allowed): bool
    {
        $key = (string) $request->input('sort');
        if (! isset($allowed[$key])) {
            return false;
        }
        $direction = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $column = $allowed[$key];
        $column instanceof Expression
            ? $query->orderByRaw($column->getValue($query->getQuery()->getGrammar()).' '.$direction)
            : $query->orderBy($column, $direction);
        $query->orderByDesc($query->getModel()->getQualifiedKeyName());

        return true;
    }
}
