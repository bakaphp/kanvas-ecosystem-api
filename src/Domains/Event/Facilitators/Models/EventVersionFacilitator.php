<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;

class EventVersionFacilitator extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'event_version_facilitators';
    protected $guarded = [];

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(Facilitator::class);
    }
}
