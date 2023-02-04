<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * CustomFieldsModules Model.
 *
 * @property int $id
 * @property int $custom_fields_id
 * @property int $is_default
 * @property string $label
 * @property string $value
 * @property string $attributes
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_delete
 */
class CustomFieldsValues extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom_fields_values';

    /**
     * Custom field modules.
     *
     * @return BelongsTo
     */
    public function customField() : BelongsTo
    {
        return $this->belongsTo(CustomFields::class, 'id', 'custom_fields_id');
    }
}
