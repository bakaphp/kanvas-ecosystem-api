<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Kanvas\Models\BaseModel;

/**
 * AppsCustomFields  Model.
 *
 * @property int $companies_id
 * @property int $users_id
 * @property string $model_name
 * @property string $entity_id
 * @property string $name
 * @property string $label
 * @property ?string $value
 */
class AppsCustomFields extends BaseModel
{
    protected $table = 'apps_custom_fields';

    protected $fillable = [
        'companies_id',
        'users_id',
        'model_name',
        'entity_id',
        'label',
        'name',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
