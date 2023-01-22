<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Attributes\Models\Attributes;

class AttributesRepository
{
    use SearchableTrait;

    public static function getModel() : Model
    {
        return new Attributes();
    }
}
