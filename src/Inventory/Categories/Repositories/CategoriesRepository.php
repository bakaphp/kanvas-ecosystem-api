<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Categories\Models\Categories;

class CategoriesRepository
{
    use SearchableTrait;

    public static function getModel() : Categories
    {
        return new Categories();
    }
}
