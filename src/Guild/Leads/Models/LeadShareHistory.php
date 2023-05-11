<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadShareHistory.
 *
 * @property int $id
 * @property int $leads_id
 * @property int $users_id
 * @property string $visitors_id
 * @property string $received_id
 * @property string $contacts_id
 * @property string action
 * @property string $request
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class LeadShareHistory extends BaseModel
{
    use KanvasScopesTrait;
    use NoAppRelationshipTrait;

    protected $table = 'leads_shared_history';
    protected $guarded = [];
}
