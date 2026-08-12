<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
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
 * Rescheduling must MOVE the appointment, not leave the old slot booked. Creates an
 * event for a lead, reschedules it, and asserts availability shows exactly one busy
 * block — at the new time, not the old one.
 */
class RescheduleCalendarEventToolTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreateCalendarEventAcceptsExplicitCompanyConfiguration(): void
    {
        [, , $lead] = $this->bootstrap();
        $lead->email = 'prospect@example.com';
        $lead->saveQuietly();
        $configuration = new EventConfigurationTool()->__invoke($lead->getId());
        $catalogs = $configuration['event_configuration'];
        $category = $catalogs['categories'][0];

        $created = new CalendarEventTool()->__invoke(
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

        $created = new CalendarEventTool()->__invoke(
            lead_id: $lead->getId(),
            title: 'Intro Meeting ' . uniqid(),
            attendee_emails: ['max@kanvas.dev'],
            start_datetime: '2026-06-20 10:00',
            end_datetime: '2026-06-20 10:30',
        );
        $this->assertSame('success', $created['status'], json_encode($created));
        $eventUuid = $created['event']['uuid'];

        $rescheduled = new RescheduleCalendarEventTool()->__invoke(
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

        $created = new CalendarEventTool()->__invoke(
            lead_id: $lead->getId(),
            title: 'Intro Meeting ' . uniqid(),
            attendee_emails: ['max@kanvas.dev'],
            start_datetime: '2026-06-20 10:00',
            end_datetime: '2026-06-20 10:30',
        );
        $this->assertSame('success', $created['status'], json_encode($created));

        $cancelled = new CancelCalendarEventTool()->__invoke(
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

    /**
     * @return array{0: Apps, 1: \Kanvas\Companies\Models\Companies, 2: Lead}
     */
    private function bootstrap(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        // Fresh owner per test so getScheduled(owner) only sees this test's events,
        // immune to event-connection leakage between tests.
        $owner = Users::factory()->create();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['leads_owner_id' => $owner->getId()]);

        return [$app, $company, $lead];
    }
}
