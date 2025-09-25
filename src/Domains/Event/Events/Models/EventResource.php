<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;

class EventResource extends BaseModel
{
    use UuidTrait;

    protected $table = 'event_resources';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'metadata' => 'array',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'event_id',
        'resources_id',
        'resources_type',
        'quantity',
        'metadata',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
