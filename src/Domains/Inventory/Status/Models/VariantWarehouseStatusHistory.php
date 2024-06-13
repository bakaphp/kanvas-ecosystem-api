<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

/**
 * Class Attributes.
 *
 * @property int status_id
 * @property int products_variants_warehouse_id
 * @property string from_date
 */
class VariantWarehouseStatusHistory extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_variants_warehouse_status_history';
    protected $guarded = [];
    protected $primaryKey = ['products_variants_warehouse_id', 'status_id'];
    protected $forceDeleting = true;

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
