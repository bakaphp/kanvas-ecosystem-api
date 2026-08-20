<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Google\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Exceptions\ValidationException;

class SyncEventToGoogleCalendarAction
{
    public function __construct(protected EventVersion $eventVersion)
    {
    }

    public function execute(): array
    {
        $this->eventVersion->loadMissing(['dates', 'event.company']);
        $event = $this->eventVersion->event;
        $externalId = $this->eventVersion->get(CustomFieldEnum::GOOGLE_CALENDAR_EVENT_ID->value)
            ?: $event->get(CustomFieldEnum::GOOGLE_CALENDAR_EVENT_ID->value);

        if ($this->eventVersion->dates->isEmpty()) {
            throw new ValidationException('Event has no dated version to synchronize.');
        }

        $dates = $this->eventVersion->dates->sortBy('event_date');
        $first = $dates->first();
        $last = $dates->last();
        $timezone = $event->company->timezone ?: 'UTC';
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $first->event_date->format('Y-m-d') . ' ' . $first->start_time, $timezone);
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $last->event_date->format('Y-m-d') . ' ' . $last->end_time, $timezone);
        $googleEventId = is_string($externalId) && $externalId !== '' ? $externalId : null;
        $result = $this->createMeeting($start, $end, $googleEventId);

        $this->eventVersion->set(CustomFieldEnum::GOOGLE_CALENDAR_EVENT_ID->value, $result['id']);
        if (! empty($result['html_link'])) {
            $this->eventVersion->set(CustomFieldEnum::GOOGLE_CALENDAR_HTML_LINK->value, $result['html_link']);
        }
        if (! empty($result['meet_link'])) {
            $this->eventVersion->set(CustomFieldEnum::GOOGLE_CALENDAR_MEET_LINK->value, $result['meet_link']);
            $event->meeting_link = $result['meet_link'];
            $event->saveQuietly();
        }

        return [
            'status' => 'success',
            'event_id' => $event->getId(),
            'event_version_id' => $this->eventVersion->getId(),
            'google_event' => $result,
        ];
    }

    protected function createMeeting(Carbon $start, Carbon $end, ?string $externalEventId): array
    {
        return new CreateGoogleCalendarMeetingAction(
            company: $this->eventVersion->event->company,
            name: $this->eventVersion->name,
            attendeeEmails: $this->resolveAttendeeEmails(),
            startDateTime: $start,
            endDateTime: $end,
            description: $this->eventVersion->description,
            withMeetLink: true,
            externalEventId: $externalEventId,
        )->execute();
    }

    protected function resolveAttendeeEmails(): array
    {
        $configuredEmails = $this->eventVersion->metadata['google_calendar']['attendee_emails'] ?? [];
        if (is_array($configuredEmails) && $configuredEmails !== []) {
            $validConfiguredEmails = collect($configuredEmails)
                ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()->values()->all();

            if ($validConfiguredEmails !== []) {
                return $validConfiguredEmails;
            }
        }

        $participantEmails = $this->eventVersion->participants()
            ->with('people.contacts')
            ->get()
            ->flatMap(fn ($participant) => $participant->people?->contacts ?? [])
            ->whereIn('contacts_types_id', [1, 9, 10])
            ->where('is_opt_out', 0)
            ->pluck('value')
            ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();

        if ($participantEmails !== []) {
            return $participantEmails;
        }

        $people = $this->eventVersion->event->resource?->people;
        if ($people === null) {
            return [];
        }

        return $people->contacts()
            ->whereIn('contacts_types_id', [1, 9, 10])
            ->where('is_opt_out', 0)
            ->pluck('value')
            ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();
    }
}
