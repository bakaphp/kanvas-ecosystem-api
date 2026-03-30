<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Override;

class EventReminder extends BaseModel
{
    protected $table = 'event_reminders';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => Json::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class, 'event_version_id');
    }
}
