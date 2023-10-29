<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantsRepository
{
    use SearchableTrait;

    public static function getModel(): Variants
    {
        return new Variants();
    }
}
