<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Status\Models\Status;

class StatusRepository
{
    use SearchableTrait;

    public static function getModel(): Status
    {
        return new Status();
    }
}
