<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Inventory\Models\BaseModel;

class CategoryResourcesEntity extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use NoAppRelationshipTrait;

    protected $table = 'category_resource_entity';
    protected $guarded = [];

    protected $fillable = [
        'categories_id',
        'resource_id',
        'resource_type',
    ];

    public function category()
    {
        return $this->belongsTo(Categories::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
