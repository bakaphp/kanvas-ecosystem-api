<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Laravel\Scout\Searchable;

/**
 * Class Attributes.
 *
 * @property int apps_id
 * @property int companies_id
 * @property int products_id
 * @property string uuid
 * @property string name
 * @property string slug
 * @property string description
 * @property string short_description
 * @property string html_description
 * @property string sku
 * @property string ean
 * @property string barcode
 * @property string serial_number
 * @property bool is_published
 */
class Status extends BaseModel
{
    use SlugTrait;
    use Searchable;

    protected $table = 'status';
    protected $guarded = [];

    /**
     * Get the user that owns the Variants.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variants::class, 'status_id');
    }

    public function variantWarehouses(): HasMany
    {
        return $this->hasMany(VariantsWarehouses::class, 'products_variants_id');
    }
}
