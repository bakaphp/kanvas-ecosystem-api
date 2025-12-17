<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Observers\EventVersionObserver;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Spatie\LaravelData\DataCollection;

#[ObservedBy([EventVersionObserver::class])]
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

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlots::class, 'time_slot_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function dates(): HasMany
    {
        return $this->hasMany(EventVersionDate::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Participant::class, 'event_version_participants')
        ->withPivot(['ticket_price', 'discount', 'invoice_date', 'metadata', 'participant_type_id'])
        ->withTimestamps();
    }

    public function eventVersionParticipants(): HasMany
    {
        return $this->hasMany(EventVersionParticipant::class, 'event_version_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => Json::class,
            'agenda' => Json::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
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

    public function addParticipant(Participant $participant): EventVersionParticipant
    {
        $defaultParticipantType = ParticipantType::fromApp($this->app)->first();

        $participantType = ParticipantType::fromApp($this->app)
            ->fromCompany($this->company)
            ->firstOrCreate(
                ['name' => 'Attendee'],
                [
                    'name' => 'Attendee',
                    'users_id' => $defaultParticipantType->users_id
                ]
            );

        $eventVersionParticipant = EventVersionParticipant::withTrashed() // includes soft-deleted records
            ->where('event_version_id', $this->getId())
            ->where('participant_id', $participant->getId())
            ->where('participant_type_id', $participantType->getId())
            ->first();

        if ($eventVersionParticipant) {
            // Restore if it was soft deleted and update 'is_deleted' to 0
            $eventVersionParticipant->restore();
            $eventVersionParticipant->is_deleted = 0;
            $eventVersionParticipant->save();
        } else {
            // Create a new record if no existing one is found
            $eventVersionParticipant = EventVersionParticipant::create([
                'event_version_id' => $this->getId(),
                'participant_id' => $participant->getId(),
                'participant_type_id' => $participantType->getId(),
                'is_deleted' => 0,
            ]);
        }

        return $eventVersionParticipant;
    }

    public function removeParticipant(Participant $participant): bool
    {
        $eventVersionParticipant = EventVersionParticipant::where('event_version_id', $this->getId())
             ->where('participant_id', $participant->getId())
             ->first();

        if ($eventVersionParticipant) {
            $eventVersionParticipant->delete();

            return true;
        }

        return false;
    }

    public function incrementAttendees(): void
    {
        $this->total_attendees++;
        $this->saveOrFail();
    }

    public function decrementAttendees(): void
    {
        $this->total_attendees--;
        $this->saveOrFail();
    }
}
