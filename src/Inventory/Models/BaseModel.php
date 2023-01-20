<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Enums\StateEnums;
use Kanvas\Inventory\Traits\AppsIdTrait;
use Kanvas\Inventory\Traits\CompaniesIdTrait;
use Kanvas\Inventory\Traits\ScopesTrait;
use Kanvas\Inventory\Traits\SourceTrait;
use Kanvas\Traits\SoftDeletes;

class BaseModel extends EloquentModel
{
    protected $connection = 'inventory';

    use HasFactory;
    use SourceTrait;
    use KanvasModelTrait;
    use AppsIdTrait;
    use CompaniesIdTrait;
    use ScopesTrait;
    use HasCustomFields;

    //use SoftDeletes;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    /**
     * Current soft delete.
     *
     * @todo change to laravel default behavior
     *
     * @return bool
     */
    public function softDelete() : bool
    {
        $this->is_deleted = StateEnums::YES->getValue();
        return $this->saveOrFail();
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeNotDeleted(Builder $query) : Builder
    {
        return $query->where('is_deleted', '=', StateEnums::NO->getValue());
    }
}
