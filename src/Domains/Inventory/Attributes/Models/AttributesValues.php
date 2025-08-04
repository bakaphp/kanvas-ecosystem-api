<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Nevadskiy\Tree\AsTree;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $attributes_id
 * @property mixed $value
 */
class AttributesValues extends BaseModel
{
    use AsTree;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use HasTranslationsDefaultFallback;

    public $table = 'attributes_values';
    public $guarded = [];
    public $translatable = ['value'];

    /**
     * attribute.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }

    /**
     * Find or create for translatable models
     */
    public static function firstOrNewTranslatable(array $attributes, string $translatableField, $translatableValue, $locale = null)
    {
        $locale = $locale ?? app()->getLocale();

        $query = static::where($attributes)
            ->where("{$translatableField}->{$locale}", $translatableValue);

        $instance = $query->first();

        if (!$instance) {
            $instance = new static($attributes);
            $instance->setTranslation($translatableField, $locale, $translatableValue);
        }

        return $instance;
    }

    public function setHasChildren(bool $value): void
    {
        $this->has_children = $value;
        $this->saveOrFail();
    }
}
