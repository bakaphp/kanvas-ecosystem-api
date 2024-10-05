<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Spatie\LaravelData\DataCollection;

class EventVersion extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;

    protected $table = 'event_versions';
    protected $guarded = [];

    protected $is_deleted;

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function dates(): HasMany
    {
        return $this->hasMany(EventVersionDate::class);
    }

    public function casts(): array
    {
        return [
            'metadata' => Json::class,
            'agenda' => Json::class,
        ];
    }

    public function getTotalAttendees(): int
    {
        return 0;
    }

    public function getNextEventVersion(Event $event): int
    {
        return $this->where('event_id', $event->getId())->max('version') + 1;
    }

    public function addDates(DataCollection $dates): void
    {
        collect($dates)->each(function ($date) {
            $this->dates()->firstOrCreate([
                'event_date' => $date['date'],
                'users_id' => $this->users_id,
                'start_time' => $date['start_time'],
                'end_time' => $date['end_time'],
            ]);
        });
    }
}
