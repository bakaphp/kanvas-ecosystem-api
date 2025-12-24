<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Class BundleItem
 * @property int $id
 * @property int $bundle_id
 * @property int $variant_id
 * @property float $quantity
 * @property string $unit
 */
class BundleItem extends BaseModel
{
    protected $table = 'bundle_items';
    protected $guarded = [];

    public function bundle()
    {
        return $this->belongsTo(Bundle::class, 'bundle_id');
    }

    public function variant()
    {
        return $this->belongsTo(Variants::class, 'variant_id');
    }
}
