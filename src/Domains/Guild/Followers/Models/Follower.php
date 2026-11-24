<?php

declare(strict_types=1);

namespace Kanvas\Guild\Followers\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Guild\Models\BaseModel;

/**
 * A user subscribed to one Guild record (a lead or a person).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $entity_id
 * @property string $entity_namespace
 * @property int $is_deleted
 */
class Follower extends BaseModel
{
    protected $table = 'followers';
    protected $guarded = [];

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_namespace', 'entity_id');
    }
}
