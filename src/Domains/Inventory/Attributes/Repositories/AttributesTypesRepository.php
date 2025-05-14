<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Attributes\Models\AttributesTypes;
use Override;

class AttributesTypesRepository
{
    use SearchableTrait;

    #[Override]
    public static function getModel(): Model
    {
        return new AttributesTypes();
    }
}
