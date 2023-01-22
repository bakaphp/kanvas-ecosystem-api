<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\BaseModel;

/**
 * CustomFieldsModules Model.
 *
 * @property int $id
 * @property int $apps_id
 * @property string $name
 * @property string $model_name
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_delete
 */
class CustomFieldsSettings extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom_fields_setting';

    /**
     * Belongs to app.
     *
     * @return BelongsTo
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
