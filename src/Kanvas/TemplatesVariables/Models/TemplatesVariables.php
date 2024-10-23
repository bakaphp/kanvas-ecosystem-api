<?php

declare(strict_types=1);

namespace Kanvas\TemplatesVariables\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Models\BaseModel;
use Kanvas\Templates\Models\Templates;

/**
 * Apps Model.
 *
 * @property int $id
 * @property string $name
 * @property string $value
 * @property int $companies_id
 * @property int $apps_id
 * @property int $system_modules_id
 * @property int $users_id
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class TemplatesVariables extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_templates_variables';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = [];


    /**
    * Template I'm based from
    *
    * @return BelongsTo <Templates>
    */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Templates::class, 'template_id');
    }
}
