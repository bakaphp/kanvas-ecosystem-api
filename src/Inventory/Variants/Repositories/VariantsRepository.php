<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Repositories;

use Kanvas\Inventory\Variants\Models\Variants;
use Baka\Traits\SearchableTrait;

class VariantsRepository
{
    use SearchableTrait;

    public static function getModel() : Variants
    {
        return new Variants();
    }
}
