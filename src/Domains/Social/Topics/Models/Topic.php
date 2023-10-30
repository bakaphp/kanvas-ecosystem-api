<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class Topics.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $slug
 * @property int $weight
 * @property int $is_feature
 * @property int $status
 */
class Topic extends BaseModel
{
    protected $table = 'topics';

    protected $guarded = [];

    public function app(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Apps::class, 'apps_id');
    }

    public function company(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Companies::class, 'companies_id');
    }

    public function user(): BelongsTo
    {
        return $this->setConnection('ecosystem')->belongsTo(Users::class, 'users_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(EntityTopics::class, 'topics_id');
    }
}
