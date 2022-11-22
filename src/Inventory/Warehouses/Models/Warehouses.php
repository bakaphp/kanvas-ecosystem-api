<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Warehouses\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Regions\Models\Regions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Baka\Traits\UuidTrait;

/**
 * Class Warehouses
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $regions_id
 * @property string $uuid
 * @property string $name
 * @property string $location
 * @property bool $is_default
 * @property int $is_published
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */

class Warehouses extends BaseModel
{
    use UuidTrait;

    protected $table = 'warehouses';

    protected $guarded = [];

    /**
     * Get the companies that owns the Warehouses
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function regions(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id');
    }

    /**
     * scopeCompany
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('companies_id', auth()->user()->default_company);
    }

    /**
     * scopeApp
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeApp(Builder $query): Builder
    {
        return $query->where('apps_id', app(Apps::class)->id);
    }
}
