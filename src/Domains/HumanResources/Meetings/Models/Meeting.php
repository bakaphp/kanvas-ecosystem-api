<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Meetings\Enums\MeetingStatusEnum;
use Kanvas\HumanResources\Meetings\Notes\Models\MeetingNote;
use Kanvas\HumanResources\Meetings\Recordings\Models\MeetingRecording;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscript;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $people_id
 * @property string $title
 * @property MeetingStatusEnum $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 */
class Meeting extends BaseModel
{
    use UuidTrait;

    protected $table = 'meetings';

    protected function casts(): array
    {
        return [
            'status' => MeetingStatusEnum::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The CRM contact the meeting is about. Assigning one labels the meeting; it stays private to its owner.
     */
    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id');
    }

    public function recording(): HasOne
    {
        return $this->hasOne(MeetingRecording::class, 'meetings_id');
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(MeetingTranscript::class, 'meetings_id')->orderBy('version');
    }

    public function latestTranscript(): HasOne
    {
        return $this->hasOne(MeetingTranscript::class, 'meetings_id')->ofMany('version', 'max');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MeetingNote::class, 'meetings_id');
    }

    /**
     * A meeting is private to the user who recorded it, so every read and write goes through its owner.
     */
    public function scopeFromOwner(
        Builder $query,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): Builder {
        return $query
            ->fromApp($app)
            ->fromCompany($company)
            ->where($this->getTable() . '.users_id', $user->getId());
    }

    /**
     * Run inside a `meeting` transaction: serializes writers that number or dedupe this meeting's children.
     */
    public function lockForWrite(): self
    {
        return self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }

    public static function getByIdFromOwner(
        mixed $id,
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): self {
        return self::query()
            ->whereKey($id)
            ->fromOwner($user, $company, $app)
            ->first() ?? throw new ModelNotFoundException('No meeting found with ID ' . $id);
    }
}
