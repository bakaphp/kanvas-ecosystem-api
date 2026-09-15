<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Match a human-written name against name columns the way an agent actually receives it.
 */
trait MatchesNameTerms
{
    /**
     * @param array<int, string> $columns
     */
    protected function scopeToNameMatch(Builder $query, array $columns, string $name): Builder
    {
        $terms = $this->nameTerms($name);

        if ($terms === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $all) use ($columns, $terms): void {
            foreach ($terms as $term) {
                $all->where(function (Builder $any) use ($columns, $term): void {
                    foreach ($columns as $column) {
                        $any->orWhere($column, 'like', '%' . $term . '%');
                    }
                });
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function nameTerms(string $name): array
    {
        $terms = preg_split('/[^\p{L}\p{N}@._\'-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significant = array_values(array_filter($terms, fn (string $term): bool => mb_strlen($term) > 1));

        return $significant !== [] ? $significant : $terms;
    }
}
