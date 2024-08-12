<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * CustomFieldsModules Model.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $users_id
 * @property int $apps_id
 * @property int $custom_fields_modules_id
 * @property int $fields_type_id
 * @property string $name
 * @property string $label
 * @property string $attributes
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_delete
 */
class CustomFields extends BaseModel
{
    use CanUseWorkflow;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom_fields';

    protected $fillable = [
        'users_id',
        'companies_id',
        'apps_id',
        'name',
        'label',
        'custom_fields_modules_id',
        'fields_type_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'id', 'companies_id');
    }

    /**
     * Custom field modules.
     *
     * @return BelongsTo
     */
    public function customFieldModule(): BelongsTo
    {
        return $this->belongsTo(CustomFieldsModules::class, 'id', 'custom_fields_modules_id');
    }

    /**
     * Belongs to.
     *
     * @return BelongsTo
     */
    public function fieldType(): BelongsTo
    {
        return $this->belongsTo(CustomFieldsTypes::class, 'id', 'fields_type_id');
    }
}
