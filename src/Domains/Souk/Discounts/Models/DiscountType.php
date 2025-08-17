<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Discounts\Factories\DiscountTypeFactory;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class DiscountType
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DiscountType extends BaseModel
{
    use NoCompanyRelationshipTrait;
    use HasFactory;

    protected $table = 'discount_types';
    protected $guarded = [];

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class, 'discount_type_id');
    }

    #[Override]
    protected static function newFactory()
    {
        return new DiscountTypeFactory();
    }
}
