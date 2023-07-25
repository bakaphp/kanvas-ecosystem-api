<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Laravel\Scout\Searchable;

/**
 * Class Attributes.
 *
 * @property int status_id
 * @property int products_variants_warehouse_id
 * @property string from_date
 */
class variantWarehouseStatusHistory extends BaseModel
{
    use SlugTrait;
    use Searchable;

    protected $table = 'products_variants_warehouse_status_history';
    protected $guarded = [];

    /**
     * Get the user that owns the Variants.
     */
    public function status(): HasOne
    {
        return $this->hasOne(Status::class, 'status_id');
    }

    public function variantWarehouses(): HasMany
    {
        return $this->hasMany(VariantsWarehouses::class, 'products_variants_warehouse_id');
    }
}
