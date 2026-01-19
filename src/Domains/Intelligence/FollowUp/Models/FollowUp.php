<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * Class FollowUp
 * @property int $id
 * @property int $follow_up_type
 * @property int $pipelines_id
 * @property string $name
 */
class FollowUp extends BaseModel
{
    protected $table = 'follow_ups';
    protected $guarded = [];

    public function days(): HasMany
    {
        return $this->hasMany(FollowUpDay::class, 'follow_ups_id');
    }
}
