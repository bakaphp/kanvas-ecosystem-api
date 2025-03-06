<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * CustomFieldEntityValue Model.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $custom_fields_id
 * @property int $entity_id
 * @property string $value
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class CustomFieldEntityValue extends BaseModel
{
    protected $table = 'custom_fields_entity_values';

    protected $fillable = [];

    public function customField()
    {
        return $this->belongsTo(CustomFields::class, 'custom_fields_id');
    }
}
