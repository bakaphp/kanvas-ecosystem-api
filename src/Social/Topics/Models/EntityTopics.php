<?php

declare(strict_types=1);

namespace Kanvas\Social\Topics\Models;

use Kanvas\Social\Models\BaseModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * @class EntityTopics
 * @property int $id
 * @property int $entity_id
 * @property string $entity_namespace
 * @property int $apps_id
 * @property int $companies_id
 * @property int $topics_id
 * @property int $users_id
 */
class EntityTopics extends BaseModel
{
    protected $table = 'entity_topics';

    protected $guarded = [];

    public function topics()
    {
        return $this->belongsTo(Topic::class, 'topics_id', 'id');
    }

    public function apps()
    {
        return $this->belongsTo(Apps::class, 'apps_id', 'id');
    }

    public function companies()
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }
}
