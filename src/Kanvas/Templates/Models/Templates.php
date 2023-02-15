<?php

declare(strict_types=1);

namespace Kanvas\Templates\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Apps Model.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string $template
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class Templates extends BaseModel
{
    use HasCustomFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_templates';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
