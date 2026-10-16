<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\PullAppointmentsAction;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * Exercises the mapping with eLead responses built in-process, so it runs in CI without credentials.
 */
final class PullAppointmentsActionTest extends TestCase
{
    private const string ELEAD_DATE = 'Y-m-d\TH:i:s.v\Z';

    private function createLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $lead->set(CustomFieldEnum::OPPORTUNITY_ID->value, 'opp-' . Str::uuid());

        return $lead;
    }

    private function activity(Lead $lead, array $overrides = []): SalesActivities
    {
        return SalesActivities::fromResponse($lead->app, $lead->company, array_merge([
            'id' => 'act-' . Str::uuid(),
            'activityType' => 'Appointment',
            'category' => 'Scheduled',
            'name' => 'Showroom Appointment',
            'dueDate' => Carbon::now('UTC')->addDays(3)->startOfHour()->format(self::ELEAD_DATE),
            'opportunityId' => $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value),
            'assignedTo' => 'Zack Morris',
        ], $overrides));
    }

    private function window(): array
    {
        $from = Carbon::now('UTC');

        return [$from, $from->copy()->addDays(PullAppointmentsAction::WINDOW_DAYS)];
    }

    private function actionWithStubbedReRead(Lead $lead): PullAppointmentsAction
    {
        return new class ($lead) extends PullAppointmentsAction {
            public ?SalesActivities $reRead = null;

            protected function fetchActivity(string $activityId): ?SalesActivities
            {
                return $this->reRead;
            }
        };
    }

    public function testCreatesAnEventLinkedToTheLeadAndIgnoresOtherActivityTypes(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        $call = $this->activity($lead, ['activityType' => 'Phone Call', 'name' => 'Day 1 Call/Text']);
        [$from, $to] = $this->window();

        $synced = new PullAppointmentsAction($lead)->apply([$appointment, $call], $from, $to);

        $this->assertCount(1, $synced);
        $event = $synced[$appointment->id];
        $this->assertSame($appointment->id, $event->get(CustomFieldEnum::ACTIVITY_ID->value));
        $this->assertSame('Showroom Appointment', $event->name);
        $this->assertSame($lead->getId(), (int) $event->resources_id);
        $this->assertSame(Lead::class, $event->resources_type);
        $this->assertTrue($lead->is($event->linkedResource(Lead::class)));
        $this->assertTrue(
            EventResource::where('event_id', $event->getId())
                ->where('resources_type', Lead::class)
                ->where('resources_id', $lead->getId())
                ->exists()
        );

        $version = $event->versions()->latest()->first();
        $duration = ConfigurationEnum::appointmentDurationMinutes($lead->company);
        $this->assertTrue($version->start_at->equalTo($appointment->dueDate()));
        $this->assertTrue($version->end_at->equalTo($appointment->dueDate()->addMinutes($duration)));
    }

    public function testReschedulesTheExistingEventInsteadOfDuplicatingIt(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        [$from, $to] = $this->window();
        $action = new PullAppointmentsAction($lead);

        $first = $action->apply([$appointment], $from, $to)[$appointment->id];

        $moved = $this->activity($lead, [
            'id' => $appointment->id,
            'dueDate' => Carbon::now('UTC')->addDays(5)->startOfHour()->format(self::ELEAD_DATE),
        ]);
        $second = $action->apply([$moved], $from, $to)[$appointment->id];

        $this->assertSame($first->getId(), $second->getId());
        $this->assertTrue($second->versions()->latest()->first()->start_at->equalTo($moved->dueDate()));
        $this->assertSame(
            1,
            EventResource::where('resources_type', Lead::class)->where('resources_id', $lead->getId())->count()
        );
    }

    public function testCancelsTheEventWhenEleadClosedTheActivity(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        [$from, $to] = $this->window();
        $action = $this->actionWithStubbedReRead($lead);

        $event = $action->apply([$appointment], $from, $to)[$appointment->id];

        $action->reRead = $this->activity($lead, [
            'id' => $appointment->id,
            'closedDate' => Carbon::now('UTC')->format(self::ELEAD_DATE),
        ]);
        $action->apply([], $from, $to);

        $this->assertSame(EventStatusEnum::CANCELLED->value, $event->fresh()->eventStatus?->name);
    }

    public function testMovesTheEventWhenEleadRescheduledItPastTheWindow(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        [$from, $to] = $this->window();
        $action = $this->actionWithStubbedReRead($lead);

        $event = $action->apply([$appointment], $from, $to)[$appointment->id];

        $farOut = $this->activity($lead, [
            'id' => $appointment->id,
            'dueDate' => Carbon::now('UTC')->addDays(45)->startOfHour()->format(self::ELEAD_DATE),
        ]);
        $action->reRead = $farOut;
        $action->apply([], $from, $to);

        $fresh = $event->fresh();
        $this->assertNotSame(EventStatusEnum::CANCELLED->value, $fresh->eventStatus?->name);
        $this->assertTrue($fresh->versions()->latest()->first()->start_at->equalTo($farOut->dueDate()));
    }

    public function testLeavesTheEventAloneWhenTheActivityCannotBeReRead(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        [$from, $to] = $this->window();
        $action = $this->actionWithStubbedReRead($lead);

        $event = $action->apply([$appointment], $from, $to)[$appointment->id];
        $action->reRead = null;
        $action->apply([], $from, $to);

        $fresh = $event->fresh();
        $this->assertNotSame(EventStatusEnum::CANCELLED->value, $fresh->eventStatus?->name);
        $this->assertTrue($fresh->versions()->latest()->first()->start_at->equalTo($appointment->dueDate()));
    }

    public function testCompanyConfiguredActivityTypesDecideWhatCountsAsAnAppointment(): void
    {
        $lead = $this->createLead();
        // Company settings write to Redis and never roll back, so the override is removed afterwards.
        $lead->company->set(ConfigurationEnum::ELEAD_APPOINTMENT_ACTIVITY_TYPES->value, 'Showroom Visit, Demo Drive');
        [$from, $to] = $this->window();

        $visit = $this->activity($lead, ['activityType' => 'Showroom Visit', 'name' => 'Visit']);
        $plain = $this->activity($lead, ['activityType' => 'Appointment', 'name' => 'Appointment']);

        try {
            $synced = new PullAppointmentsAction($lead)->apply([$visit, $plain], $from, $to);
        } finally {
            $lead->company->del(ConfigurationEnum::ELEAD_APPOINTMENT_ACTIVITY_TYPES->value);
        }

        $this->assertSame([$visit->id], array_keys($synced));
    }
}
