<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Meeting\Models\BaseModel;

/**
 * @property int $id
 * @property int $meeting_transcripts_id
 * @property string $source_key
 * @property string $provider_label
 * @property string|null $display_name
 */
class MeetingTranscriptSpeaker extends BaseModel
{
    protected $table = 'meeting_transcript_speakers';

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(MeetingTranscript::class, 'meeting_transcripts_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(MeetingTranscriptSegment::class, 'meeting_transcript_speakers_id')->orderBy('position');
    }

    public static function getByIdFromOwner(
        mixed $id,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): self {
        return self::query()
            ->whereKey($id)
            ->whereHas(
                'transcript',
                fn (Builder $transcript) => $transcript->fromMeetingOwner($user, $company, $app)
            )
            ->first() ?? throw new ModelNotFoundException('No meeting transcript speaker found with ID ' . $id);
    }
}
