<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Categories\Models\Categories;

class Products extends BaseModel
{
    protected $table = 'products';
    protected $guarded = [];

    /**
     * categories
     *
     * @return void
     */
    public function categories()
    {
        return $this->belongsToMany(Categories::class, 'products_categories', 'products_id', 'categories_id');
    }
}
