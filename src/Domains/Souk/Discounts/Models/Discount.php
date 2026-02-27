<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Souk\Discounts\Factories\DiscountFactory;
use Kanvas\Souk\Discounts\Observers\DiscountObserver;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * Class Discount
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $discount_type_id
 * @property float $value
 * @property bool $is_percentage
 * @property float|null $min_order_value
 * @property float|null $max_discount_amount
 * @property string|null $code
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property bool $is_active
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property bool $is_one_per_customer
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy(DiscountObserver::class)]
class Discount extends BaseModel
{
    use UuidTrait;
    use HasFactory;
    use HasCustomFields;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    protected $table = 'discounts';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'value' => 'float',
            'min_order_value' => 'float',
            'max_discount_amount' => 'float',
            'is_percentage' => 'boolean',
            'is_active' => 'boolean',
            'is_one_per_customer' => 'boolean',
            'is_deleted' => 'boolean',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class, 'discount_type_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(DiscountCondition::class, 'discount_id');
    }

    public function orderDiscounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class, 'discount_id');
    }

    public function orderItemDiscounts(): HasMany
    {
        return $this->hasMany(OrderItemDiscount::class, 'discount_id');
    }

    /**
     * Check if the discount is valid for the given date
     */
    public function isValidForDate(?DateTimeInterface $date = null): bool
    {
        $date = $date ?? now();

        if (empty($this->end_dat)) {
            return true;
        }

        if ($this->start_date && $date < $this->start_date) {
            return false;
        }

        if ($this->end_date && $date > $this->end_date) {
            return false;
        }

        return true;
    }

    /**
     * Check if the discount can be used
     */
    public function canBeUsed(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->isValidForDate()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function isOverUsageLimit(): bool
    {
        return $this->usage_limit !== null && $this->usage_count >= $this->usage_limit;
    }

    /**
     * Increment the usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Calculate the discount amount for a given order value
     */
    public function calculateDiscountAmount(float $orderValue): float
    {
        if ($this->min_order_value !== null && $orderValue < $this->min_order_value) {
            return 0;
        }

        $discountAmount = $this->is_percentage
            ? ($orderValue * $this->value / 100.0)
            : $this->value;

        if ($this->max_discount_amount !== null && $discountAmount > $this->max_discount_amount) {
            $discountAmount = $this->max_discount_amount;
        }

        return min($discountAmount, $orderValue);
    }

    #[Override]
    protected static function newFactory()
    {
        return new DiscountFactory();
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);

        $customIndex = $app->get('app_custom_discount_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'discount_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => $this->uuid,
            'id' => (string) $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'value' => $this->value,
            'is_percentage' => $this->is_percentage,
            'is_active' => $this->is_active,
            'discount_type' => [
                'id' => $this->discountType?->id,
                'name' => $this->discountType?->name,
            ],
            'company' => [
                'id' => $this->companies_id,
                'name' => $this->company?->name,
            ],
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'start_date' => $this->start_date?->timestamp,
            'end_date' => $this->end_date?->timestamp,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->is_deleted;
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                ],
                [
                    'name' => 'description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'code',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'value',
                    'type' => 'float',
                    'sort' => true,
                ],
                [
                    'name' => 'is_percentage',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'is_active',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'discount_type',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'company',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'start_date',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'end_date',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $searchQuery->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $searchQuery->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($searchQuery->model->isTypesense()) {
            $searchQuery->options([
                'query_by' => 'name,description,code',
            ]);
        }

        return $searchQuery;
    }
}
