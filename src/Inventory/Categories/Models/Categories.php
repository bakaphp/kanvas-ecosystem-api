<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Categories\Models;

use Kanvas\Inventory\Models\BaseModel;
use Baka\Traits\UuidTrait;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Baka\Traits\SlugTrait;
use Kanvas\Inventory\Traits\ScopesTrait;

class Categories extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use ScopesTrait;

    protected $table = 'categories';
    protected $guarded = [];

    /**
     *
     * @return BelongsTo
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id', 'id');
    }

    /**
     * companies
     *
     * @return BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

}
