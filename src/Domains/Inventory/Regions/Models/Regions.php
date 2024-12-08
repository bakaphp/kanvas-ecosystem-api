<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions as ModelsRegions;
use Kanvas\Traits\DefaultTrait;

/**
 * Class Regions.
 * @deprecated v2.0
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
class Regions extends ModelsRegions
{
    use UuidTrait;
    use SlugTrait;
    use DefaultTrait;
    use SoftDeletesTrait;

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
