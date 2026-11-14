<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Elead;

use Baka\Contracts\DefaultRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Activities\StopResponseClockActivity;
use Kanvas\Connectors\Elead\Handlers\ELeadHandler;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Events\AgentReachedOutToLeadEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Enums\WorkflowEnum;
use ReflectionClass;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;

/**
 * Elead's response clock is stopped by a rule the Elead integration gives its company, on the
 * `agent-reached-out` event every reach-out raises; a company without Elead has no such rule.
 */
final class StopResponseClockActivityTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->assertCount(3, $handler->defaultRules(), 'the two SalesAssist pull rules stay');
    }

    public function testALeadEleadIsNotTimingIsLeftAlone(): void
    {
        $app = app(Apps::class);
        $lead = $this->lead();

        $result = new StopResponseClockActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $lead,
            $app,
            ['message_id' => 1]
        );

        $this->assertSame('Not a lead Elead is timing; nothing to stop', $result['message']);
    }

    public function testAReachOutIsAnnouncedToListenersAndToTheIntegrationsRules(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        $lead = $this->lead();
        $agent = Agent::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->create();

        AgentReachedOutToLeadEvent::announce($lead, $agent, ['message_ids_sent' => [41]]);

        Event::assertDispatched(
            AgentReachedOutToLeadEvent::class,
            fn (AgentReachedOutToLeadEvent $event): bool => $event->lead->is($lead) && $event->agent->is($agent)
        );
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
