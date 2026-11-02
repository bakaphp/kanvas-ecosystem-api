<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Arr;

/**
 * An id-list parameter as a model actually passes it: a list, a bare id, or a numeric string.
 * Declaring the parameter as a typed `array` turns the bare id into a TypeError the model never
 * sees an explanation for, so the tools take the union and normalize here.
 */
trait NormalizesIdListParam
{
    /**
     * @return list<int>
     */
    protected function normalizeIds(array|int|string|null $ids): array
    {
        $normalized = [];

        foreach (Arr::wrap($ids) as $id) {
            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        return $normalized;
    }
}
