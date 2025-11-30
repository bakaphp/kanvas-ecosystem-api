<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

class CategoryResources extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'category_resource';
    protected $guarded = [];

    protected $fillable = [
        'categories_id',
        'system_modules_id',
    ];

    public function category()
    {
        return $this->belongsTo(Categories::class);
    }

    public function systemModule()
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }
}
