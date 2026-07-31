<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

/**
 * Neuron's ToolProperty can't emit a JSON-schema `items` for an ARRAY param, so strict providers
 * (Gemini) reject it. Expose order-type names as a comma-separated scalar STRING and split it here.
 */
trait ParsesOrderTypesFilter
{
    /**
     * @return list<string>|null
     */
    protected function parseOrderTypes(?string $orderTypes): ?array
    {
        if ($orderTypes === null || trim($orderTypes) === '') {
            return null;
        }

        $names = array_values(array_filter(
            array_map('trim', explode(',', $orderTypes)),
            fn (string $name): bool => $name !== '',
        ));

        return $names === [] ? null : $names;
    }
}
