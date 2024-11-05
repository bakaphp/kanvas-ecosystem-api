<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Models\BaseModel as KanvasBaseModel;

/**
 * Class Regions.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $currency_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $short_slug
 * @property ?string settings = null
 * @property int $is_default
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Regions extends KanvasBaseModel
{
    use UuidTrait;
    use SlugTrait;
    use DefaultTrait;

    protected $table = 'regions';
    protected $guarded = [];

    public function currencies(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouses::class, 'regions_id');
    }

    public function hasDependencies(): bool
    {
        return $this->warehouses()->exists();
    }
}
