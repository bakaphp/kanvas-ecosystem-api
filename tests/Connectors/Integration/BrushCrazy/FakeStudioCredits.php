<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Support\StudioCredits;

/**
 * Stands in for the BrushCrazy database, which tests have no connection to.
 */
final class FakeStudioCredits extends StudioCredits
{
    public int $calls = 0;

    /**
     * @param  array<int, int>  $cents  bc user id => cents
     */
    public function __construct(
        private array $cents = [],
    ) {
    }

    public function forUsers(int $bcStudioId, array $bcUserIds): array
    {
        $this->calls++;

        return array_intersect_key($this->cents, array_flip($bcUserIds));
    }
}
