<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class, 'bundle_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'variant_id');
    }
}
