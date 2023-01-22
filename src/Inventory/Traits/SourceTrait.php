<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

trait SourceTrait
{
    /**
     * setLinkedSource.
     *
     * @param  string $source
     * @param  string $sourceId
     *
     * @return void
     */
    public function setLinkedSource(string $source, string $sourceId)
    {
        $this->set("{$source}_id", $sourceId);
    }
}
