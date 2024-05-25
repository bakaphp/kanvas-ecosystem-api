<?php

declare(strict_types=1);

namespace Domains\ActionEngine\Engagements\Models;

use Baka\Traits\UuidTrait;
use Kanvas\ActionEngine\Models\BaseModel;

/**
 * Class Engagement.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $companies_actions_id
 * @property int $message_id
 * @property int $leads_id
 * @property int $people_id
 * @property int $pipelines_stages_id
 * @property string $entity_uuid
 * @property string $slug
 */
class Engagement extends BaseModel
{
    use UuidTrait;

    protected $table = 'engagements';
    protected $guarded = [];
}
