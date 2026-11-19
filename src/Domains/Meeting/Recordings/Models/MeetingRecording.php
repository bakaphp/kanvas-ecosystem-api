<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Recordings\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Models\BaseModel;

/**
 * @property int $id
 * @property int $meetings_id
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $duration_ms
 * @property array|null $channel_map
 * @property Carbon $uploaded_at
 * @property Carbon|null $purged_at
 */
class MeetingRecording extends BaseModel
{
    protected $table = 'meeting_recordings';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'channel_map' => Json::class,
            'uploaded_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meetings_id');
    }
}
