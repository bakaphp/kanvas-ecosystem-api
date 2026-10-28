<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\Concerns;

/**
 * The one "nothing found" answer the inventory tools give, so a tenant's RAG
 * handoff rule keyed on `status: no_matches` fires whichever tool the agent used.
 */
trait RespondsWithNoInventoryMatches
{
    protected function noMatchesResponse(string $request): array
    {
        return [
            'status' => 'no_matches',
            'reason' => 'no_matching_inventory',
            'message' => "No products found matching '{$request}' in the scoped inventory.",
            'inventory_availability_confirmed' => false,
            'instruction' => 'Do not interpret this result as confirmed dealership unavailability. '
                . 'Follow the RAG business rule "No Matching Inventory Results — STRICT HANDOFF RULE".',
        ];
    }
}
