<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Class LeadParticipantType.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string|null $description
 */
class LeadParticipantType extends BaseModel
{
    use CanUseWorkflow;

    protected $table = 'leads_participants_types';
    protected $guarded = [];
}
