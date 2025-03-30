<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Class Leads.
 *
 * @property int $leads_id
 * @property int $peoples_id
 * @property int $participants_types_id
 */
class LeadParticipant extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    use CanUseWorkflow;

    protected $table = 'leads_participants';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(
            People::class,
            'peoples_id',
            'id'
        );
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            Lead::class,
            'leads_id',
            'id'
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(
            PeopleRelationship::class,
            'participants_types_id',
            'id'
        );
    }
}
