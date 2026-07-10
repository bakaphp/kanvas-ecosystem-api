<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Traits;

use Kanvas\ActionEngine\Tasks\Models\TaskListItem;

/**
 * Main-buyer and co-buyer verification tasks share the same company action, so the only
 * reliable discriminator is the task item's config: a co-buyer item carries a
 * `cobuyer-picker` step, a main-buyer item does not. Both selection (which item a message
 * completes) and sibling fan-out (which items a completion cascades to) must key off the
 * same marker, or the two paths disagree and completion leaks across roles.
 */
trait IdentifiesCoBuyerTaskItems
{
    protected const CO_BUYER_CONFIG_STEP_TYPE = 'cobuyer-picker';

    protected function taskItemHasCoBuyerStep(TaskListItem $item): bool
    {
        $steps = $item->config['steps'] ?? [];

        if (! is_array($steps)) {
            return false;
        }

        foreach ($steps as $step) {
            if (($step['type'] ?? null) === self::CO_BUYER_CONFIG_STEP_TYPE) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL predicate keeping a query to one buyer role. A NULL/step-less config counts as
     * main-buyer (0), so ordinary items are unaffected.
     *
     * @param literal-string $configColumn
     *
     * @return literal-string
     */
    protected function coBuyerConfigPredicate(string $configColumn, bool $isCoBuyer): string
    {
        $hasStep = "COALESCE(JSON_CONTAINS(JSON_EXTRACT({$configColumn}, '$.steps[*].type'), '\""
            . self::CO_BUYER_CONFIG_STEP_TYPE . "\"'), 0)";

        return $hasStep . ' = ' . ($isCoBuyer ? '1' : '0');
    }
}
