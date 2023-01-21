<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Models\BaseModel;

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
class Regions extends BaseModel
{
    use UuidTrait;
    use SlugTrait;

    protected $table = 'regions';
    protected $guarded = [];

    /**
     * Get the companies that owns the Warehouses.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function companies() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apps() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function currencies() : BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }
}
