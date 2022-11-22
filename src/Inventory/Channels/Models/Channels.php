<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Channels\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Baka\Traits\UuidTrait;

/**
 * Class Channels.
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $users_id
 * @property string $uuid
 * @property string $name
 * @property string $description
 * @property string $slug
 * @property int $is_published
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Channels extends BaseModel
{
    use UuidTrait;

    protected $table = 'channels';
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
     * users
     *
     * @return BelongsTo
     */
    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
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
