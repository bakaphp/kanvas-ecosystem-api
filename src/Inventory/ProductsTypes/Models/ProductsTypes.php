<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\Models;

use Kanvas\Inventory\Models\BaseModel;
use Baka\Traits\UuidTrait;

class ProductsTypes extends BaseModel
{
    use UuidTrait;
    
    protected $table = "products_types";

    protected $guarded=[];
}
