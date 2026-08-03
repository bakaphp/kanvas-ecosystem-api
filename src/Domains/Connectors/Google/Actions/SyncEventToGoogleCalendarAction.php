<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Google\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;

class SyncEventToGoogleCalendarAction
{
    public function __construct(protected Event $event)
    {
    }

    public function execute(): array
    {
        $externalId = $this->event->get(CustomFieldEnum::GOOGLE_CALENDAR_EVENT_ID->value);

        $version = $this->event->versions()->with('dates')->latest('version')->first();
        if ($version === null || $version->dates->isEmpty()) {
            throw new ValidationException('Event has no dated version to synchronize.');
        }

        $dates = $version->dates->sortBy('event_date');
        $first = $dates->first();
        $last = $dates->last();
        $timezone = $this->event->company->timezone ?: 'UTC';
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $first->event_date->format('Y-m-d') . ' ' . $first->start_time, $timezone);
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $last->event_date->format('Y-m-d') . ' ' . $last->end_time, $timezone);
        $googleEventId = is_string($externalId) && $externalId !== ''
            ? $externalId
            : hash('sha256', 'kanvas-event-' . $this->event->uuid);
        $result = $this->createMeeting($start, $end, $googleEventId);

        $this->event->set(CustomFieldEnum::GOOGLE_CALENDAR_EVENT_ID->value, $result['id']);
        if (! empty($result['html_link'])) {
            $this->event->set(CustomFieldEnum::GOOGLE_CALENDAR_HTML_LINK->value, $result['html_link']);
        }
        if (! empty($result['meet_link'])) {
            $this->event->set(CustomFieldEnum::GOOGLE_CALENDAR_MEET_LINK->value, $result['meet_link']);
            $this->event->meeting_link = $result['meet_link'];
            $this->event->saveQuietly();
        }

        return ['status' => 'success', 'event_id' => $this->event->getId(), 'google_event' => $result];
    }

    protected function createMeeting(Carbon $start, Carbon $end, string $externalEventId): array
    {
        return new CreateGoogleCalendarMeetingAction(
            company: $this->event->company,
            name: $this->event->name,
            attendeeEmails: $this->resolveAttendeeEmails(),
            startDateTime: $start,
            endDateTime: $end,
            description: $this->event->description,
            withMeetLink: true,
            externalEventId: $externalEventId,
        )->execute();
    }

    protected function resolveAttendeeEmails(): array
    {
        $version = $this->event->versions()->latest('version')->first();
        $configuredEmails = $version?->metadata['google_calendar']['attendee_emails'] ?? [];
        if (is_array($configuredEmails) && $configuredEmails !== []) {
            $validConfiguredEmails = collect($configuredEmails)
                ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()->values()->all();

            if ($validConfiguredEmails !== []) {
                return $validConfiguredEmails;
            }
        }

        $people = $this->event->resource?->people;
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
