<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;

/**
 * CustomFieldTypes Model.
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_delete
 */
class CustomFieldsTypes extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom_fields_types';


}
