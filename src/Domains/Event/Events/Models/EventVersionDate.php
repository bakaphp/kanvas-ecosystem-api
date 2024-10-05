<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;

class EventVersionDate extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'event_version_dates';
    protected $guarded = [];

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }

    public function casts(): array
    {
        return [
            'event_date' => 'datetime',
        ];
    }
}
