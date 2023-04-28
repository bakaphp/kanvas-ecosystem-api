<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\KanvasScopesTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class LeadReceiver.
 *
 * @property int $id
 * @property string $uuid
 * @property int $companies_id
 * @property int|null $companies_branches_id
 * @property string $name
 * @property int $users_id
 * @property int $agents_id
 * @property int $rotations_id
 * @property string $source_name
 * @property string|null $template
 * @property int $total_leads
 * @property int $is_default
 */
class LeadReceiver extends BaseModel
{
    use UuidTrait;
    use KanvasScopesTrait;
    use NoAppRelationshipTrait;

    protected $table = 'lead_receivers';
    protected $guarded = [];
}
