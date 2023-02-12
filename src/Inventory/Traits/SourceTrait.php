<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

trait SourceTrait
{
    /**
     * set entity relationship with third party source.
     */
    public function setLinkedSource(string $source, string $sourceId): void
    {
        $this->set($source . '_id', $sourceId);
    }
}
