<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Channels\Models\Channels;

class ChannelRepository
{
    use SearchableTrait;

    public static function getModel(): Channels
    {
        return new Channels();
    }
}
