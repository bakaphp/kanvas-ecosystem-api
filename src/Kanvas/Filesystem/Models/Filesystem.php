<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Models;

use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Filesystem Model.
 *
 * @property int $id
 * @property string $uuid;
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
    use UuidTrait;
    use Cachable;
    use CanUseWorkflow;
    
    protected $table = 'filesystem';

    /**
     * Users relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Companies relationship.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Apps relationship.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
