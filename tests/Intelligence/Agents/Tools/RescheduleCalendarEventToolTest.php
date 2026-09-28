<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Enums\EventReminderStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Notifications\EventParticipantNotification;
use Kanvas\Event\Events\Repositories\EventScheduleRepository;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CancelCalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\EventConfigurationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\RescheduleCalendarEventTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The agent's internal appointment flow: a move must free the old slot, the owner can never be
 * double-booked, and the lead is emailed at the time the company actually means.
 */
class RescheduleCalendarEventToolTest extends TestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));
        Notification::fake();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCreateCalendarEventAcceptsExplicitCompanyConfiguration(): void
    {
        [, , $lead] = $this->bootstrap();
        $lead->email = 'prospect@example.com';
        $lead->saveQuietly();
        $configuration = $this->withTenant(new EventConfigurationTool())->__invoke($lead->getId());
        $catalogs = $configuration['event_configuration'];
        $category = $catalogs['categories'][0];

        $created = $this->withTenant(new CalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            title: 'Configured Meeting ' . uniqid(),
            attendee_emails: ['max@kanvas.dev'],
            start_datetime: '2026-06-20 10:00',
            end_datetime: '2026-06-20 10:30',
            theme_id: $catalogs['themes'][0]['id'],
            theme_area_id: $catalogs['theme_areas'][0]['id'],
            status_id: $catalogs['statuses'][0]['id'],
            type_id: $category['event_type_id'],
            class_id: $category['event_class_id'],
            category_id: $category['id'],
        );

        $this->assertSame('success', $created['status'], json_encode($created));
        $this->assertSame(
            ['max@kanvas.dev', 'prospect@example.com'],
            collect($created['event']['attendees'])->sort()->values()->all(),
        );
    }

    public function testRescheduleMovesAppointmentAndFreesOldSlot(): void
    {
        [$app, $company, $lead] = $this->bootstrap();

        $created = $this->withTenant(new CalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            title: 'Intro Meeting ' . uniqid(),
            attendee_emails: ['max@kanvas.dev'],
            start_datetime: '2026-06-20 10:00',
            end_datetime: '2026-06-20 10:30',
        );
        $this->assertSame('success', $created['status'], json_encode($created));
        $eventUuid = $created['event']['uuid'];

        $rescheduled = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $eventUuid,
            start_datetime: '2026-06-21 14:00',
            end_datetime: '2026-06-21 14:30',
        );
        $this->assertSame('success', $rescheduled['status'], json_encode($rescheduled));
        // Same event — moved in place, not a new one.
        $this->assertSame($created['event']['id'], $rescheduled['event']['id']);

        $busy = new EventScheduleRepository()->getScheduled(
            $app,
            $company,
            Carbon::parse('2026-06-19 00:00', $company->timezone),
            Carbon::parse('2026-06-22 23:59', $company->timezone),
            $lead->owner,
        );

        $this->assertCount(1, $busy, 'Exactly one busy block after reschedule — old slot freed');
        $this->assertSame('2026-06-21', $busy->first()->start->format('Y-m-d'));
        $this->assertSame('14:00', $busy->first()->start->format('H:i'));
    }

    public function testCancelFreesTheSlot(): void
    {
        [$app, $company, $lead] = $this->bootstrap();

        $created = $this->withTenant(new CalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            title: 'Intro Meeting ' . uniqid(),
            attendee_emails: ['max@kanvas.dev'],
            start_datetime: '2026-06-20 10:00',
            end_datetime: '2026-06-20 10:30',
        );
        $this->assertSame('success', $created['status'], json_encode($created));

        $cancelled = $this->withTenant(new CancelCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $created['event']['uuid'],
        );
        $this->assertSame('success', $cancelled['status'], json_encode($cancelled));

        $busy = new EventScheduleRepository()->getScheduled(
            $app,
            $company,
            Carbon::parse('2026-06-19 00:00', $company->timezone),
            Carbon::parse('2026-06-22 23:59', $company->timezone),
            $lead->owner,
        );

        $this->assertCount(0, $busy, 'Cancelled appointment must free the slot');
    }

    public function testCreateRefusesASlotTheOwnerAlreadyHasBooked(): void
    {
        [$app, $company, $lead] = $this->bootstrap();
        $otherLead = $this->leadForSameOwner($lead);

        $first = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        $this->assertSame('success', $first['status'], json_encode($first));

        $overlapping = $this->createAppointment($otherLead, '2026-06-20 10:15', '2026-06-20 10:45');

        $this->assertSame('error', $overlapping['status'], json_encode($overlapping));
        $this->assertSame('2026-06-20T10:00:00+00:00', Carbon::parse($overlapping['conflicts'][0]['start'])->utc()->toIso8601String());
        $this->assertArrayNotHasKey('name', $overlapping['conflicts'][0], 'Another prospect\'s appointment name must not leak');
        $this->assertCount(1, $this->ownerBusy($app, $company, $lead));
    }

    public function testRescheduleRefusesAnotherAppointmentsSlotButMayOverlapItsOwn(): void
    {
        [$app, $company, $lead] = $this->bootstrap();
        $otherLead = $this->leadForSameOwner($lead);

        $first = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        $second = $this->createAppointment($otherLead, '2026-06-20 11:00', '2026-06-20 11:30');

        $intoTakenSlot = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $otherLead->getId(),
            event_uuid: $second['event']['uuid'],
            start_datetime: '2026-06-20 10:15',
            end_datetime: '2026-06-20 10:45',
        );
        $this->assertSame('error', $intoTakenSlot['status'], json_encode($intoTakenSlot));

        $shiftedOverItself = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $first['event']['uuid'],
            start_datetime: '2026-06-20 10:15',
            end_datetime: '2026-06-20 10:45',
        );
        $this->assertSame('success', $shiftedOverItself['status'], json_encode($shiftedOverItself));
        $this->assertCount(2, $this->ownerBusy($app, $company, $lead));
    }

    public function testRescheduleGuardsTheCalendarTheAppointmentIsOnAfterTheLeadIsReassigned(): void
    {
        [, , $lead] = $this->bootstrap();
        $otherLead = $this->leadForSameOwner($lead);

        $first = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        $this->createAppointment($otherLead, '2026-06-20 11:00', '2026-06-20 11:30');

        $lead->leads_owner_id = Users::factory()->create()->getId();
        $lead->saveQuietly();

        $moved = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $first['event']['uuid'],
            start_datetime: '2026-06-20 11:15',
            end_datetime: '2026-06-20 11:45',
        );

        $this->assertSame('error', $moved['status'], json_encode($moved));
    }

    public function testRescheduleRefusesACancelledAppointment(): void
    {
        [, , $lead] = $this->bootstrap();

        $created = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        $this->withTenant(new CancelCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $created['event']['uuid'],
        );

        $moved = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $created['event']['uuid'],
            start_datetime: '2026-06-21 14:00',
            end_datetime: '2026-06-21 14:30',
        );

        $this->assertSame('error', $moved['status'], json_encode($moved));
        $this->assertStringContainsString('cancelled', $moved['message']);
    }

    public function testCreateEmailsTheLeadAtTheCompanysLocalTime(): void
    {
        [, $company, $lead] = $this->bootstrap(timezone: 'America/Santo_Domingo');
        $email = 'prospect-' . uniqid() . '@example.com';
        $lead->email = $email;
        $lead->saveQuietly();

        $created = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        $this->assertSame('success', $created['status'], json_encode($created));

        $this->assertTrue($created['lead_notified']);

        $version = Event::getById($created['event']['id'])->versions()->first();
        // 10:00 in Santo Domingo (UTC-4) is 14:00 UTC.
        $this->assertSame('2026-06-20 14:00:00', $version->start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(
            $version->participants()->with('people')->get()
                ->contains(fn ($participant) => $participant->people->getEmails()->contains('value', $email)),
            'The lead must be a participant so the booking emails reach them'
        );

        Notification::assertSentOnDemand(
            EventParticipantNotification::class,
            fn (EventParticipantNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $email
                && $notification->getTemplateName() === EmailTemplateEnum::BOOKING_CREATED->value
                && $notification->getData()['start_time'] === '10:00'
        );
    }

    public function testRescheduleEmailsTheNewTimeAndMovesTheReminder(): void
    {
        [, , $lead] = $this->bootstrap(timezone: 'America/Santo_Domingo');
        $lead->email = 'prospect-' . uniqid() . '@example.com';
        $lead->saveQuietly();

        $created = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');

        $moved = $this->withTenant(new RescheduleCalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            event_uuid: $created['event']['uuid'],
            start_datetime: '2026-06-21 14:00',
            end_datetime: '2026-06-21 14:30',
        );
        $this->assertSame('success', $moved['status'], json_encode($moved));
        $this->assertTrue($moved['lead_notified']);

        $version = Event::getById($created['event']['id'])->versions()->first();
        $this->assertSame('2026-06-21 18:00:00', $version->start_at->utc()->format('Y-m-d H:i:s'));

        Notification::assertSentOnDemand(
            EventParticipantNotification::class,
            fn (EventParticipantNotification $notification): bool => $notification->getTemplateName() === EmailTemplateEnum::BOOKING_UPDATED->value
                && $notification->getData()['start_date'] === '2026-06-21'
                && $notification->getData()['start_time'] === '14:00'
        );

        $reminder = $version->reminders()->where('status', EventReminderStatusEnum::PENDING->value)->sole();
        $this->assertSame('2026-06-21T18:00:00+00:00', $reminder->metadata['start_at']);
    }

    public function testAgentIsToldWhenTheLeadWasNotEmailed(): void
    {
        [$app, , $lead] = $this->bootstrap();
        $lead->email = 'prospect-' . uniqid() . '@example.com';
        $lead->saveQuietly();

        try {
            $app->set(ConfigurationEnum::SEND_EMAILS->value, 0);

            $created = $this->createAppointment($lead, '2026-06-20 10:00', '2026-06-20 10:30');
        } finally {
            $app->del(ConfigurationEnum::SEND_EMAILS->value);
        }

        $this->assertSame('success', $created['status'], json_encode($created));
        $this->assertFalse($created['lead_notified']);
        $this->assertStringContainsString('NOT emailed', $created['note']);
        Notification::assertNothingSent();
    }

    /**
     * @return array{0: Apps, 1: Companies, 2: Lead}
     */
    private function bootstrap(?string $timezone = null): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if ($timezone !== null) {
            $company->timezone = $timezone;
            $company->saveOrFail();
        }

        new Setup($app, $user, $company)->run();

        // Fresh owner per test so getScheduled(owner) only sees this test's events,
        // immune to event-connection leakage between tests.
        $owner = Users::factory()->create();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['leads_owner_id' => $owner->getId()]);

        return [$app, $company, $lead];
    }

    private function leadForSameOwner(Lead $lead): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($lead->apps_id, $lead->companies_id)
            ->create(['leads_owner_id' => $lead->leads_owner_id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createAppointment(Lead $lead, string $start, string $end): array
    {
        return $this->withTenant(new CalendarEventTool())->__invoke(
            lead_id: $lead->getId(),
            title: 'Intro Meeting ' . uniqid(),
            attendee_emails: [],
            start_datetime: $start,
            end_datetime: $end,
        );
    }

    private function ownerBusy(Apps $app, Companies $company, Lead $lead): Collection
    {
        return new EventScheduleRepository()->getScheduled(
            $app,
            $company,
            Carbon::parse('2026-06-19 00:00', $company->timezone),
            Carbon::parse('2026-06-22 23:59', $company->timezone),
            $lead->owner,
        );
    }

    /**
     * Lead tools resolve their lead against the tenant on their context, so a bare instance
     * (no withContext) intentionally resolves nothing — mirror what the agent wiring does.
     *
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
