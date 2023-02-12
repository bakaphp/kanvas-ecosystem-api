<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Filesystem Model.
 *
 * @property int $id
 * @property int $users_id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string $path
 * @property string $url
 * @property string $size
 * @property string $file_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class Filesystem extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'filesystem';

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Companies relationship.
     *
     * @return Companies
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Apps relationship.
     *
     * @return Apps
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
