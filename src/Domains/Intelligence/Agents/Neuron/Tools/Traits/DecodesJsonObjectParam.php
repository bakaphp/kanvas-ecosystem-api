<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use function is_array;
use function is_string;

/**
 * Free-form key→value params can't be declared as OBJECT: Gemini rejects the entire
 * request when an object schema has no `properties`, and it has no `additionalProperties`
 * to express "arbitrary keys" with (Sentry KANVAS-ECOSYSTEM-606's sibling failure). Such
 * params are declared as STRING carrying a JSON object and decoded here.
 *
 * A real array is still accepted so the tools keep working if a provider hands back
 * structured input rather than a string.
 */
trait DecodesJsonObjectParam
{
    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonObjectParam(array|string|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
