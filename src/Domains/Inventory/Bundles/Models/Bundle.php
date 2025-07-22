<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Class Bundle
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $variant_id
 * @property string $name
 * @property string|null $description
 * @property string $execution_mode
 * @property bool $expose_as_product
 */
class Bundle extends BaseModel
{
    use HasFilesystemTrait;
    protected $table = 'bundles';
    protected $guarded = [];

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            Variants::class,
            'bundle_items',
            'bundle_id',
            'variant_id'
        )->withPivot('quantity', 'unit')->withTimestamps();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'variant_id');
    }
}
