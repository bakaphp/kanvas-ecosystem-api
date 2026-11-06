<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\CancelLeadFollowUpPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\GetLeadFollowUpPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\SetLeadFollowUpEnabledTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\FollowUp\SetLeadFollowUpPlanTool;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

final class LeadFollowUpPlanToolsTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsLeadNotes;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    private const string BODY = 'This 2025 Mercedes is available. Please contact me regarding details.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company()->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user()->getId());
        $this->seedFollowUpAgent();
    }

    public function testTheOwnerSetsAPlanAndItIsRecorded(): void
    {
        $lead = $this->seedLead();

        $result = $this->setTool($this->user())->__invoke(
            lead_id: $lead->getId(),
            channel: 'SMS',
            message: self::BODY,
            every_hours: 24,
            max_attempts: 7,
        );

        $this->assertSame('ok', $result['outcome'], json_encode($result));
        $this->assertSame('sms', $result['channel']);
        $this->assertSame(7, $result['max_attempts']);
        $this->assertTrue($result['first_sms_carries_opt_out_notice']);
        $this->assertFalse($result['follow_up_turned_on']);

        $plan = $lead->refresh()->getFollowUpPlan();
        $this->assertSame(self::BODY, $plan->body);
        $this->assertSame(1440, $plan->intervalMinutes);
        $this->assertSame($this->user()->getId(), $plan->setByUsersId);
        $this->assertTrue($plan->stopOnReply);
        $this->assertStringContainsString(self::BODY, $this->latestLeadNoteContent($lead));
        $this->assertNotNull($this->ledger($lead, 'lead.follow_up.plan.set'));
    }

    public function testSomeoneElseWithoutAdminIsRefused(): void
    {
        $lead = $this->seedLead();
        $stranger = Users::factory()->create();

        $result = $this->setTool($stranger)->__invoke(lead_id: $lead->getId(), channel: 'sms', message: self::BODY);

        $this->assertSame('denied', $result['outcome']);
        $this->assertNull($lead->refresh()->getFollowUpPlan());
    }

    public function testAMutedLeadIsRefusedWithTheReason(): void
    {
        $lead = $this->seedLead();
        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::OFF->value);

        $result = $this->setTool($this->user())->__invoke(lead_id: $lead->getId(), channel: 'sms', message: self::BODY);

        $this->assertSame('denied', $result['outcome']);
        $this->assertSame('ai_mode_off', $result['reason']);
        $this->assertNull($lead->refresh()->getFollowUpPlan());
    }

    public function testALeadWithFollowUpOffNeedsOneConfirmationThenTurnsItOn(): void
    {
        $lead = $this->seedLead();
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

        $first = $this->setTool($this->user())->__invoke(lead_id: $lead->getId(), channel: 'sms', message: self::BODY);

        $this->assertSame('noop', $first['outcome']);
        $this->assertTrue($first['needs_confirmation']);
        $this->assertNull($lead->refresh()->getFollowUpPlan());

        $second = $this->setTool($this->user())->__invoke(
            lead_id: $lead->getId(),
            channel: 'sms',
            message: self::BODY,
            confirm_enable_follow_up: true,
        );

        $this->assertSame('ok', $second['outcome'], json_encode($second));
        $this->assertTrue($second['follow_up_turned_on']);
        $this->assertTrue($lead->refresh()->isAiFollowUpEnabled());
        $this->assertNotNull($lead->getFollowUpPlan());
    }

    public function testBadArgumentsAreNamed(): void
    {
        $lead = $this->seedLead();
        $tool = $this->setTool($this->user());

        $this->assertSame('invalid_args', $tool->__invoke(lead_id: $lead->getId(), channel: 'fax', message: self::BODY)['outcome']);
        $this->assertSame('invalid_args', $tool->__invoke(lead_id: $lead->getId(), channel: 'sms', message: '  ')['outcome']);
        $this->assertSame('invalid_args', $tool->__invoke(lead_id: $lead->getId(), channel: 'sms', message: self::BODY, start_at: 'next tuesday-ish')['outcome']);
        $this->assertSame('error', $tool->__invoke(lead_id: 999999999, channel: 'sms', message: self::BODY)['status']);
    }

    public function testGetReportsTheRunningPlanThenCancelEndsIt(): void
    {
        $lead = $this->seedLead();
        $this->setTool($this->user())->__invoke(
            lead_id: $lead->getId(),
            channel: 'sms',
            message: self::BODY,
            start_at: Carbon::now()->addDay()->format('Y-m-d H:i'),
        );

        $status = $this->getTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('ok', $status['outcome']);
        $this->assertTrue($status['is_active']);
        $this->assertSame(0, $status['attempts_sent']);
        $this->assertNull($status['paused_reason']);
        $this->assertNotNull($status['next_due_at']);
        $this->assertStringContainsString('running', $status['note']);

        $cancelled = $this->cancelTool($this->user())->__invoke(lead_id: $lead->getId());
        $this->assertSame('ok', $cancelled['outcome']);
        $this->assertNull($lead->refresh()->getFollowUpPlan());
        $this->assertSame(ManualFollowUpPlan::REASON_CANCELLED, $lead->getLastFollowUpPlan()->doneReason);

        $again = $this->cancelTool($this->user())->__invoke(lead_id: $lead->getId());
        $this->assertSame('noop', $again['outcome']);

        $after = $this->getTool()->__invoke(lead_id: $lead->getId());
        $this->assertFalse($after['is_active']);
        $this->assertSame('cancelled', $after['done_reason']);
    }

    public function testTheFollowUpSwitchPausesAPlanWithoutEndingIt(): void
    {
        $lead = $this->seedLead();
        $this->setTool($this->user())->__invoke(lead_id: $lead->getId(), channel: 'sms', message: self::BODY);

        $off = $this->enabledTool($this->user())->__invoke(lead_id: $lead->getId(), enabled: false);

        $this->assertSame('ok', $off['outcome']);
        $this->assertTrue($off['plan_active']);
        $this->assertFalse($lead->refresh()->isAiFollowUpEnabled());
        $this->assertNotNull($lead->getFollowUpPlan());
        $this->assertSame('lead_follow_up_disabled', $this->getTool()->__invoke(lead_id: $lead->getId())['paused_reason']);

        $this->assertSame('noop', $this->enabledTool($this->user())->__invoke(lead_id: $lead->getId(), enabled: false)['outcome']);

        $on = $this->enabledTool($this->user())->__invoke(lead_id: $lead->getId(), enabled: true);
        $this->assertSame('ok', $on['outcome']);
        $this->assertNull($this->getTool()->__invoke(lead_id: $lead->getId())['paused_reason']);
    }

    public function testGetWithoutAnyPlanIsNotFound(): void
    {
        $this->assertSame('not_found', $this->getTool()->__invoke(lead_id: $this->seedLead()->getId())['outcome']);
    }

    private function setTool(Users $actor): SetLeadFollowUpPlanTool
    {
        return new SetLeadFollowUpPlanTool()->withContext(app(Apps::class), $this->company(), $actor);
    }

    private function cancelTool(Users $actor): CancelLeadFollowUpPlanTool
    {
        return new CancelLeadFollowUpPlanTool()->withContext(app(Apps::class), $this->company(), $actor);
    }

    private function enabledTool(Users $actor): SetLeadFollowUpEnabledTool
    {
        return new SetLeadFollowUpEnabledTool()->withContext(app(Apps::class), $this->company(), $actor);
    }

    private function getTool(): GetLeadFollowUpPlanTool
    {
        return new GetLeadFollowUpPlanTool()->withContext(app(Apps::class), $this->company(), $this->user());
    }

    private function user(): Users
    {
        return auth()->user();
    }

    private function company(): Companies
    {
        return $this->user()->getCurrentCompany();
    }

    private function seedLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $this->company()->getId())
            ->create(['leads_owner_id' => $this->user()->getId()]);
    }

    private function seedFollowUpAgent(): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId(app(Apps::class)->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);

        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
                'agent_type_id' => $agentType->getId(),
                'user_id' => $this->user()->getId(),
                'is_active' => true,
                'role' => ['background' => [], 'steps' => [], 'output' => ''],
            ]);
    }

    private function ledger(Lead $lead, string $eventType): ?Event
    {
        return Event::query()
            ->where('apps_id', app(Apps::class)->getId())
            ->where('event_type', $eventType)
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
    }
}
