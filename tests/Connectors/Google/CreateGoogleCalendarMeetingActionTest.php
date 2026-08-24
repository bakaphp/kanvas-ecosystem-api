<?php

declare(strict_types=1);

namespace Tests\Connectors\Google;

use Carbon\Carbon;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Kanvas\Connectors\Google\Actions\CreateGoogleCalendarMeetingAction;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Tests\TestCase;

class CreateGoogleCalendarMeetingActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Google Calendar integration tests are skipped in CI');
        }
    }

    public function testItCreatesAnEventWithAttendeesAndConferenceDataUsingTheOfficialClient(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value, [
            'calendar_id' => 'calendar@example.com',
            'auth_profiles' => [
                'service_account' => [
                    'credentials_json' => ['type' => 'service_account'],
                ],
            ],
        ]);

        $action = new class (
            company: $company,
            name: 'Finance Meeting',
            attendeeEmails: ['lead@example.com', 'owner@example.com', 'lead@example.com', 'invalid'],
            startDateTime: Carbon::parse('2026-08-13 10:00', 'America/New_York'),
            endDateTime: Carbon::parse('2026-08-13 10:30', 'America/New_York'),
        ) extends CreateGoogleCalendarMeetingAction {
            public ?Event $insertedEvent = null;

            protected function createCalendarService(array $config): Calendar
            {
                return new Calendar(new Client());
            }

            protected function insertEvent(Calendar $service, string $calendarId, Event $event): Event
            {
                $this->insertedEvent = $event;

                return new Event([
                    'id' => 'google-event-123',
                    'summary' => $event->getSummary(),
                    'description' => $event->getDescription(),
                    'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
                    'htmlLink' => 'https://calendar.google.com/event?id=123',
                ]);
            }
        };

        $result = $action->execute();

        $this->assertSame('google-event-123', $result['id']);
        $this->assertSame('calendar@example.com', $result['calendar_id']);
        $this->assertSame(['lead@example.com', 'owner@example.com'], $result['attendees']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['meet_link']);
        $this->assertNotNull($action->insertedEvent?->getConferenceData()?->getCreateRequest());
        $this->assertCount(2, $action->insertedEvent?->getAttendees() ?? []);
    }

    public function testItRetriesWithoutMeetWhenTheCalendarRejectsTheConferenceType(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value, [
            'calendar_id' => 'calendar@example.com',
            'auth_profiles' => [
                'service_account' => [
                    'credentials_json' => ['type' => 'service_account'],
                ],
            ],
        ]);

        $action = new class (
            company: $company,
            name: 'Dealership Visit',
            attendeeEmails: ['lead@example.com'],
            startDateTime: Carbon::parse('2026-08-13 10:00', 'America/New_York'),
            endDateTime: Carbon::parse('2026-08-13 10:30', 'America/New_York'),
        ) extends CreateGoogleCalendarMeetingAction {
            public int $attempts = 0;
            public ?Event $insertedEvent = null;

            protected function createCalendarService(array $config): Calendar
            {
                return new Calendar(new Client());
            }

            protected function insertEvent(Calendar $service, string $calendarId, Event $event): Event
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('Google Calendar event creation failed: Invalid conference type value.');
                }

                $this->insertedEvent = $event;

                return new Event([
                    'id' => 'google-event-without-meet',
                    'summary' => $event->getSummary(),
                    'htmlLink' => 'https://calendar.google.com/event?id=without-meet',
                ]);
            }
        };

        $result = $action->execute();

        $this->assertSame(2, $action->attempts);
        $this->assertSame('google-event-without-meet', $result['id']);
        $this->assertNull($result['meet_link']);
        $this->assertNull($action->insertedEvent?->getConferenceData());
        $this->assertSame(['lead@example.com'], $result['attendees']);
    }

    public function testItUpdatesAnExistingEventWithTheLatestDetailsAndAttendees(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value, [
            'calendar_id' => 'calendar@example.com',
            'auth_profiles' => [
                'service_account' => [
                    'credentials_json' => ['type' => 'service_account'],
                ],
            ],
        ]);

        $action = new class (
            company: $company,
            name: 'Updated Meeting',
            attendeeEmails: ['new-attendee@example.com'],
            startDateTime: Carbon::parse('2026-08-13 11:00', 'America/New_York'),
            endDateTime: Carbon::parse('2026-08-13 11:30', 'America/New_York'),
            description: 'Updated description',
            externalEventId: 'google-event-123',
        ) extends CreateGoogleCalendarMeetingAction {
            public ?Event $updatedEvent = null;
            public ?string $updatedEventId = null;

            protected function createCalendarService(array $config): Calendar
            {
                return new Calendar(new Client());
            }

            protected function updateEvent(Calendar $service, string $calendarId, string $eventId, Event $event): Event
            {
                $this->updatedEventId = $eventId;
                $this->updatedEvent = $event;

                return new Event([
                    'id' => $eventId,
                    'summary' => $event->getSummary(),
                    'description' => $event->getDescription(),
                    'hangoutLink' => 'https://meet.google.com/updated',
                    'htmlLink' => 'https://calendar.google.com/event?id=updated',
                ]);
            }
        };

        $result = $action->execute();

        $this->assertSame('google-event-123', $action->updatedEventId);
        $this->assertSame('google-event-123', $result['id']);
        $this->assertSame('Updated Meeting', $action->updatedEvent?->getSummary());
        $this->assertSame('Updated description', $action->updatedEvent?->getDescription());
        $this->assertSame('new-attendee@example.com', $action->updatedEvent?->getAttendees()[0]->getEmail());
    }
}
