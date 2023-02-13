<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
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
     * companies.
     *
     * @return BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }
}
