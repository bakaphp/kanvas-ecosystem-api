<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

/**
 * Comma-separated, because a bare `PropertyType::ARRAY` makes Gemini reject every tool in the turn.
 * Shared so dispatch and continue cannot drift into parsing it differently.
 */
trait SplitsReferenceSlugs
{
    /**
     * @return list<string>
     */
    protected function splitReferenceSlugs(?string $references): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', (string) $references)),
            static fn (string $slug): bool => $slug !== '',
        ));
    }
}
