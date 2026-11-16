<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Models\BaseModel;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;

/**
 * @property int $id
 * @property int $meetings_id
 * @property int $version
 * @property string $provider
 * @property string $model
 * @property string|null $provider_job_id
 * @property MeetingTranscriptStatusEnum $status
 * @property string|null $error_code
 * @property Carbon|null $completed_at
 * @property Meeting $meeting
 */
class MeetingTranscript extends BaseModel
{
    protected $table = 'meeting_transcripts';

    protected function casts(): array
    {
        return [
            'status' => MeetingTranscriptStatusEnum::class,
            'completed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meetings_id');
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(MeetingTranscriptSpeaker::class, 'meeting_transcripts_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(MeetingTranscriptSegment::class, 'meeting_transcripts_id')->orderBy('position');
    }

    public function scopeFromMeetingOwner(
        Builder $query,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): Builder {
        return $query->whereHas(
            'meeting',
            fn (Builder $meeting) => $meeting->fromOwner($user, $company, $app)
        );
    }

    public static function getByIdFromOwner(
        mixed $id,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): self {
        return self::query()
            ->whereKey($id)
            ->fromMeetingOwner($user, $company, $app)
            ->first() ?? throw new ModelNotFoundException('No meeting transcript found with ID ' . $id);
    }
}
