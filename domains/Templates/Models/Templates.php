<?php

declare(strict_types=1);

namespace Kanvas\Templates\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Users\Models\Users;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Models\Companies;

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
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'templates';

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Companies
     */
    public function company() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return Apps
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
