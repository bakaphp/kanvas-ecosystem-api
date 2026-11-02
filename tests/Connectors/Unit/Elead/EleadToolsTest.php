<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\PullAppointmentsAction;
use Kanvas\Connectors\Elead\Entities\Lead as EleadOpportunity;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Neuron\Tools\GetEleadAppointmentsTool;
use Kanvas\Connectors\Elead\Neuron\Tools\GetEleadOpportunityTool;
use Kanvas\Connectors\Elead\Neuron\Tools\ReadEleadActivityHistoryTool;
use Kanvas\Connectors\Elead\Neuron\Tools\RefreshLeadFromEleadTool;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Tests\TestCase;

/**
 * The eLead reads are answered in-process through the tools' protected seams, so this runs in CI
 * without credentials — the same approach as PullAppointmentsActionTest.
 */
final class EleadToolsTest extends TestCase
{
    private const string ELEAD_DATE = 'Y-m-d\TH:i:s.v\Z';

    private function createLead(bool $connected = true, bool $withOpportunity = true): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();
        $company->set(CustomFieldEnum::COMPANY->value, $connected ? 'sub-' . Str::random(8) : '');
        // Pinned because company settings live in Redis and outlive any other test's override.
        $company->set(ConfigurationEnum::ELEAD_APPOINTMENT_ACTIVITY_TYPES->value, 'Appointment');

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

        if ($withOpportunity) {
            $lead->set(CustomFieldEnum::OPPORTUNITY_ID->value, 'opp-' . Str::uuid());
        }

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

    /**
     * @param SalesActivities[] $activities
     */
    private function appointmentsTool(array $activities): GetEleadAppointmentsTool
    {
        return new class ($activities) extends GetEleadAppointmentsTool {
            public int $reads = 0;

            public function __construct(private readonly array $activities)
            {
                parent::__construct();
            }

            protected function fetchActivities(
                Lead $lead,
                Carbon $from,
                Carbon $to,
                bool $openOnly
            ): array {
                $this->reads++;

                return $this->activities;
            }
        };
    }

    /**
     * @param SalesActivities[] $activities
     */
    private function historyTool(array $activities): ReadEleadActivityHistoryTool
    {
        return new class ($activities) extends ReadEleadActivityHistoryTool {
            public ?bool $openOnly = null;

            public function __construct(private readonly array $activities)
            {
                parent::__construct();
            }

            protected function fetchActivities(
                Lead $lead,
                Carbon $from,
                Carbon $to,
                bool $openOnly
            ): array {
                $this->openOnly = $openOnly;

                return $this->activities;
            }
        };
    }

    private function opportunity(Lead $lead, array $overrides = []): EleadOpportunity
    {
        $opportunity = new EleadOpportunity();
        $opportunity->app = $lead->app;
        $opportunity->company = $lead->company;
        $opportunity->assign(array_merge([
            'id' => $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value),
            'status' => 'Active',
            'subStatus' => 'Appointment Set',
            'upType' => 'Internet',
            'source' => 'Website',
            'salesTeam' => [
                ['id' => 'u-1', 'firstName' => 'Zack', 'lastName' => 'Morris', 'isPrimary' => true, 'positionName' => 'Salesperson'],
                ['id' => 'u-2', 'firstName' => 'Kelly', 'lastName' => 'Kapowski', 'isPrimary' => false],
            ],
            'soughtVehicles' => [['year' => 2024, 'make' => 'Toyota', 'model' => 'RAV4']],
        ], $overrides));

        return $opportunity;
    }

    private function customerFacingAgent(Lead $lead): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($lead->app->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesAgent::class]);

        return Agent::factory()
            ->withAppId($lead->app->getId())
            ->withCompanyId($lead->company->getId())
            ->create([
                'name' => 'Sofia ' . Str::random(6),
                'user_id' => $lead->user->getId(),
                'agent_type_id' => $agentType->getId(),
            ]);
    }

    public function testAppointmentsAreReadFromEleadAndMirroredOntoTheLead(): void
    {
        $lead = $this->createLead();
        $appointment = $this->activity($lead);
        $call = $this->activity($lead, ['activityType' => 'Phone Call', 'name' => 'Day 1 Call/Text']);

        $result = $this->appointmentsTool([$appointment, $call])
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
        $this->assertSame(1, $result['count']);
        $row = $result['appointments'][0];
        $this->assertSame($appointment->id, $row['activity_id']);
        $this->assertSame('Zack Morris', $row['assigned_to']);
        $this->assertTrue($row['is_open']);

        $event = Event::getByCustomField(CustomFieldEnum::ACTIVITY_ID->value, $appointment->id, $lead->company);
        $this->assertNotNull($event, 'the appointment should now exist as a Kanvas event on the lead');
        $this->assertSame($event->getId(), $row['kanvas_event_id']);
        $this->assertSame($lead->getId(), (int) $event->resources_id);
    }

    public function testNoOpenAppointmentIsANoopNotAFailure(): void
    {
        $lead = $this->createLead();

        $result = $this->appointmentsTool([])
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('no open appointment', $result['note']);
    }

    public function testASecondIdenticalCallInATurnIsAnsweredWithoutReadingEleadAgain(): void
    {
        $lead = $this->createLead();
        $tool = $this->appointmentsTool([$this->activity($lead)])
            ->withContext($lead->app, $lead->company, $lead->user);

        $tool->__invoke($lead->getId());
        $second = $tool->__invoke($lead->getId());

        $this->assertSame(1, $tool->reads);
        $this->assertTrue($second['repeat_call']);
        $this->assertSame(1, $second['count']);
    }

    public function testRefusesWhenEleadIsNotConnectedForTheCompany(): void
    {
        $lead = $this->createLead(connected: false);

        $result = $this->appointmentsTool([$this->activity($lead)])
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertStringContainsString('not connected', $result['error']);
    }

    public function testALeadWithoutAnOpportunityIsNotFound(): void
    {
        $lead = $this->createLead(withOpportunity: false);

        $result = $this->appointmentsTool([])
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('no eLead opportunity', $result['note']);
    }

    public function testHistoryListsEleadActivitiesNewestFirstWithOpenState(): void
    {
        $lead = $this->createLead();
        $done = $this->activity($lead, [
            'activityType' => 'Phone Call',
            'category' => 'Completed',
            'name' => 'Outbound Call',
            'dueDate' => Carbon::now('UTC')->subDays(2)->format(self::ELEAD_DATE),
            'completedDate' => Carbon::now('UTC')->subDays(2)->format(self::ELEAD_DATE),
            'outcome' => 'Contacted',
        ]);
        $upcoming = $this->activity($lead);
        $tool = $this->historyTool([$done, $upcoming]);

        $result = $tool
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
        $this->assertFalse($tool->openOnly);
        $this->assertSame([$upcoming->id, $done->id], array_column($result['activity'], 'activity_id'));
        $this->assertTrue($result['activity'][0]['is_open']);
        $this->assertFalse($result['activity'][1]['is_open']);
        $this->assertSame('Contacted', $result['activity'][1]['outcome']);
    }

    public function testHistoryRejectsAWindowWiderThanEleadAllows(): void
    {
        $lead = $this->createLead();

        $result = $this->historyTool([])
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId(), from_date: '2026-01-01', to_date: '2026-03-15');

        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $result['outcome']);
        $this->assertStringContainsString('31 days', $result['error']);
    }

    public function testHistoryIsRefusedOnACustomerSurface(): void
    {
        $lead = $this->createLead();

        $result = $this->historyTool([$this->activity($lead)])
            ->withContext(
                $lead->app,
                $lead->company,
                $lead->user,
                $this->customerFacingAgent($lead)
            )
            ->__invoke($lead->getId());

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertStringContainsString('customer conversation', $result['error']);
    }

    public function testAppointmentsStayAvailableOnACustomerSurface(): void
    {
        $lead = $this->createLead();

        $result = $this->appointmentsTool([$this->activity($lead)])
            ->withContext(
                $lead->app,
                $lead->company,
                $lead->user,
                $this->customerFacingAgent($lead)
            )
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
        $this->assertSame(1, $result['count']);
    }

    public function testOpportunityPresentsWhatEleadHolds(): void
    {
        $lead = $this->createLead();
        $opportunity = $this->opportunity($lead);

        $tool = new class ($opportunity) extends GetEleadOpportunityTool {
            public function __construct(private readonly EleadOpportunity $opportunity)
            {
                parent::__construct();
            }

            protected function fetchOpportunity(Lead $lead): EleadOpportunity
            {
                return $this->opportunity;
            }
        };

        $result = $tool
            ->withContext($lead->app, $lead->company, $lead->user)
            ->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
        $this->assertSame('Active', $result['status']);
        $this->assertSame('Appointment Set', $result['sub_status']);
        $this->assertTrue($result['is_active']);
        $this->assertFalse($result['in_showroom']);
        $this->assertSame('Zack Morris', $result['sales_team'][0]['name']);
        $this->assertTrue($result['sales_team'][0]['is_primary']);
        $this->assertFalse($result['sales_team'][1]['is_primary']);
        $this->assertSame('RAV4', $result['sought_vehicles'][0]['model']);
    }

    public function testRefreshReportsTheLeadAsKanvasNowHoldsIt(): void
    {
        $lead = $this->createLead();
        $opportunity = $this->opportunity($lead);
        $appointment = $this->activity($lead);

        $tool = new class ($opportunity, $appointment) extends RefreshLeadFromEleadTool {
            public int $syncs = 0;

            public function __construct(
                private readonly EleadOpportunity $opportunity,
                private readonly SalesActivities $appointment
            ) {
                parent::__construct();
            }

            protected function syncLead(Lead $lead): EleadOpportunity
            {
                $this->syncs++;

                return $this->opportunity;
            }

            protected function pullAppointments(Lead $lead): array
            {
                $from = Carbon::now('UTC');

                return new PullAppointmentsAction($lead)
                    ->apply([$this->appointment], $from, $from->copy()->addDays(PullAppointmentsAction::WINDOW_DAYS));
            }
        };
        $tool->withContext($lead->app, $lead->company, $lead->user);

        $result = $tool->__invoke($lead->getId());
        $again = $tool->__invoke($lead->getId());

        $this->assertSame(ToolOutcomeEnum::OK->value, $result['outcome']);
        $this->assertSame('Active', $result['elead_status']);
        $this->assertSame(1, $result['appointments_synced']);
        $this->assertSame('Showroom Appointment', $result['upcoming_appointments'][0]['name']);
        $this->assertNotNull($result['upcoming_appointments'][0]['start_at']);
        $this->assertTrue($again['repeat_call']);
        $this->assertSame(1, $tool->syncs);
    }
}
