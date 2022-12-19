<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Models;

use Kanvas\Inventory\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Products\Models\Products;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;

/**
 * Class Attributes
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
class Variants extends BaseModel
{
    use SlugTrait;
    use UuidTrait;

    protected $table = 'products_variants';
    protected $guarded = [];

    /**
     * Get the user that owns the Variants
     *
     * @return BelongsTo
     */
    public function products(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }
}
