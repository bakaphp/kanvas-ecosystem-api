<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Handlers;

use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;
use Override;

final class DoesntHaveLeadHandler extends WhereConditionsHandler
{
    private const string STATUS_COLUMN = 'leads_status_id';

    /**
     * @param  array<string, mixed>  $whereConditions
     */
    #[Override]
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        // Lighthouse wraps a custom-handler @whereHasConditions payload as
        // ['HAS' => ['relation' => ..., 'amount' => ..., 'operator' => ..., 'condition' => <leaf tree>]],
        // not the flat leaf tree other handlers in this file assume.
        $root = $whereConditions['HAS']['condition'] ?? $whereConditions;
        $leaves = $this->collectLeaves($root);
        $scopeLeaves = array_values(
            array_filter($leaves, fn (array $leaf) => $leaf['column'] !== self::STATUS_COLUMN)
        );

        $builder->whereHas(
            'leads',
            function (object $query) use ($scopeLeaves): void {
                $this->applyLeaves($query, $scopeLeaves);
            }
        );

        $builder->whereDoesntHave(
            'leads',
            function (object $query) use ($leaves): void {
                $this->applyLeaves($query, $leaves);
            }
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $leaves
     */
    private function applyLeaves(object $query, array $leaves): void
    {
        foreach ($leaves as $leaf) {
            $this->assertValidColumnReference($leaf['column']);
            $this->operator->applyConditions($query, $leaf, 'and');
        }
    }

    /**
     * @param  array<string, mixed>  $whereConditions
     * @return array<int, array<string, mixed>>
     */
    private function collectLeaves(array $whereConditions): array
    {
        $leaves = [];

        if (array_key_exists('column', $whereConditions)) {
            $leaves[] = $whereConditions;
        }

        foreach (['AND', 'OR'] as $group) {
            if (array_key_exists($group, $whereConditions)) {
                foreach ($whereConditions[$group] as $nested) {
                    $leaves = [...$leaves, ...$this->collectLeaves($nested)];
                }
            }
        }

        return $leaves;
    }
}
