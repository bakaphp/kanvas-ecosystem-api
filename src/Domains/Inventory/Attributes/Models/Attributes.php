<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Support\Str;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Observers\AttributeObserver;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypesAttributes;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Rennokki\QueryCache\Traits\QueryCacheable;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string uuid
 * @property string $name
 * @property int $is_filterable
 * @property int $is_searchable
 * @property int $is_visible
 * @property int $weight
 */
#[ObservedBy(AttributeObserver::class)]
class Attributes extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CascadeSoftDeletes;
    use DatabaseSearchableTrait;
    use HasTranslationsDefaultFallback;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    //use QueryCacheable;

    public $table = 'attributes';
    public $translatable = ['name'];

    public $guarded = [];
    public $cacheFor = 604800; //1 week
    public $cacheTags = ['attributes'];
    public $cachePrefix = 'attributes_';
    public $cacheDriver = 'redis';
    protected static $flushCacheOnUpdate = true;

    protected $cascadeDeletes = ['variantAttributes','defaultValues'];

    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributesTypes::class, 'attributes_type_id');
    }

    public function variantAttributes(): HasMany
    {
        return $this->hasMany(VariantsAttributes::class, 'attributes_id');
    }

    public function productsAttributes(): HasMany
    {
        return $this->hasMany(ProductsAttributes::class, 'attributes_id');
    }

    public function productsTypesAttributes(): HasMany
    {
        return $this->hasMany(ProductsTypesAttributes::class, 'attributes_id');
    }

    /**
     * attributes values from pivot
     */
    public function value(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->pivot || ! isset($this->pivot->value)) {
                    return null;
                }

                $value = $this->pivot->value;

                return Str::isJson($value) ? json_decode($value, true) : $value;
            }
        );
    }

    /**
     * Attributes can have a default list of values , so we can generate dropdown list
     */
    public function defaultValues(): HasMany
    {
        return $this->hasMany(AttributesValues::class, 'attributes_id');
    }

    public function hasDependencies(): bool
    {
        return $this->productsAttributes()->exists()
        || $this->variantAttributes()->exists()
        || $this->productsTypesAttributes()->exists();
    }

    public function addDefaultValues(array $values): void
    {
        $valueObjects = array_map(
            fn ($value) => ['value' => $value],
            $values
        );

        (new AddAttributeValue($this, $valueObjects))->execute();
    }

    public function addDefaultValue(mixed $value): void
    {
        $this->addDefaultValues([$value]);
    }

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $query = self::traitSearch($query, $callback)->where('apps_id', $app->getId());

        /*         if ($user instanceof UserInterface && ! $user->isAppOwner()) {
                    $query->where('companies_id', $user->getCurrentCompany()->getId());
                } */

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
