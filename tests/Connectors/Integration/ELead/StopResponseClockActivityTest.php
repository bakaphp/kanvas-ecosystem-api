<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Elead;

use Baka\Contracts\DefaultRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Activities\StopResponseClockActivity;
use Kanvas\Connectors\Elead\Handlers\ELeadHandler;
use Kanvas\Connectors\SalesAssist\Activities\RecordAgentReachOutActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\WorkflowEnum;
use ReflectionClass;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;
use Workflow\Models\StoredWorkflow;

/**
 * Elead's response clock is stopped by a rule the Elead integration gives its company, on the
 * `agent-reached-out` event every reach-out raises; a company without Elead has no such rule.
 */
final class StopResponseClockActivityTest extends TestCase
{
    use DatabaseTransactions;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'crm', 'workflow', 'intelligence', 'social'];

    public function testTheEleadIntegrationDeclaresTheRuleOnAgentReachedOut(): void
    {
        $handler = new ReflectionClass(ELeadHandler::class)->newInstanceWithoutConstructor();

        $rule = array_find(
            $handler->defaultRules(),
            fn (DefaultRule $rule): bool => $rule->activity === StopResponseClockActivity::class
        );

        $this->assertInstanceOf(DefaultRule::class, $rule);
        $this->assertSame(WorkflowEnum::AGENT_REACHED_OUT, $rule->trigger);
        $this->assertSame(Lead::class, $rule->model);
        $this->assertCount(4, $handler->defaultRules(), 'the SalesAssist rules stay beside it');
        $this->assertNotNull(array_find(
            $handler->defaultRules(),
            fn (DefaultRule $rule): bool => $rule->activity === RecordAgentReachOutActivity::class
                && $rule->trigger === WorkflowEnum::AGENT_REACHED_OUT
        ));
    }

    public function testALeadEleadIsNotTimingIsLeftAlone(): void
    {
        $app = app(Apps::class);
        $lead = $this->lead();

        $result = new StopResponseClockActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $lead,
            $app,
            ['message_id' => 1]
        );

        $this->assertSame('Not a lead Elead is timing; nothing to stop', $result['message']);
    }

    public function testAReachOutIsAnnouncedToTheIntegrationsRulesWithWhatWasSent(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead();
        $agent = Agent::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->create();

        AgentReachOutAction::announceReachOut($lead, $agent, ['channels_sent' => ['sms'], 'message_ids_sent' => [41]]);

        $params = $this->firedWith($reachedOut)->sole();
        $this->assertSame($agent->getId(), $params['agent_id']);
        $this->assertSame(41, $params['message_id']);
        $this->assertSame([41], $params['message_ids_sent']);
        $this->assertSame([], $params['message_ids_scheduled']);
    }

    public function testAReachOutThatReachedNobodyIsNotAnnounced(): void
    {
        $reachedOut = $this->watchWorkflowEvent(WorkflowEnum::AGENT_REACHED_OUT->value, Lead::class);
        $lead = $this->lead();
        $agent = Agent::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->create();

        AgentReachOutAction::announceReachOut($lead, $agent, ['status' => 'skipped']);

        $this->assertCount(0, $this->firedWith($reachedOut));
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
