<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Leads;

class LeadsRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new Leads();
    }
}
