<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Models\BaseModel;
use Baka\Traits\DatabaseSearchableTrait;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

/**
 * Class Attributes.
 *
 * @property int apps_id
 * @property int companies_id
 * @property int products_id
 * @property string uuid
 * @property string name
 * @property string slug
 */
class Status extends BaseModel
{
    use SlugTrait;
    use DatabaseSearchableTrait;
    use DefaultTrait;

    protected $table = 'status';
    protected $guarded = [];

    /**
     * Get the user that owns the Variants.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variants::class, 'status_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Products::class, 'status_id');
    }

    public function variantWarehouses(): HasMany
    {
        return $this->hasMany(VariantsWarehouses::class, 'products_variants_id');
    }

    public function hasDependencies(): bool
    {
        return $this->products()->exists() || $this->variants()->exists();
    }
}
