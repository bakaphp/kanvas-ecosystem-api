<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Customers\Models\PeoplesRelationships;
use Kanvas\Guild\Models\BaseModel;
use Laravel\Scout\Searchable;

/**
 * Class Leads.
 *
 * @property int $leads_id
 * @property int $peoples_id
 * @property int $participants_types_id
 *
 */
class LeadsParticipants extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $primaryKey = ['leads_id', 'peoples_id'];
    protected $table = 'leads_participants';
    protected $guarded = [];

    public function people(): BelongsTo
    {
        return $this->belongsTo(
            Peoples::class,
            'people_id',
            'id'
        );
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            Leads::class,
            'leads_id',
            'id'
        );
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(
            PeoplesRelationships::class,
            'participants_types_id',
            'id'
        );
    }
}
