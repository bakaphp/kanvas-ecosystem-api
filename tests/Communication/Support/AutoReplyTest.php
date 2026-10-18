<?php

declare(strict_types=1);

namespace Tests\Communication\Support;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Actions\EnsureAutoReplyRuleAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Support\AutoReplyGate;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Communication\Workflows\AgentChannelResponderActivity as ConversationResponderActivity;
use Kanvas\Connectors\WaSender\Workflows\AgentChannelResponderActivity as WaSenderResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Kanvas\Workflow\Services\WorkflowActionCatalog;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class AutoReplyTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    /**
     * Stands in for a provider's own responder from before the conversation responder: any
     * catalogued activity will do.
     */
    private const string LEGACY_RESPONDER = WaSenderResponderActivity::class;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(AutoReplyPolicy::SETTING);

        parent::tearDown();
    }

    public function testAMessageFromBeforeConnectionsAnswersAsItAlwaysHas(): void
    {
        $this->assertNull(AutoReplyGate::refusal($this->inbound([])));
    }

    public function testAConnectionSwitchedOffStandsTheResponderDown(): void
    {
        $connection = $this->defaultSmsConnection('fake', '+18095550190', ['enabled' => false]);

        $this->assertSame('connection_disabled', AutoReplyGate::refusal($this->inbound([
            'connection_id' => $connection->getId(),
            'activation_version' => $connection->activation_version,
        ])));
    }

    public function testATurnQueuedBeforeAnOffAndOnIsStale(): void
    {
        $connection = $this->defaultSmsConnection('fake', '+18095550191', ['activation_version' => 3]);

        $this->assertSame('stale_activation', AutoReplyGate::refusal($this->inbound([
            'connection_id' => $connection->getId(),
            'activation_version' => 2,
        ])));
    }

    public function testCommunicationWorksWithoutAnAgent(): void
    {
        $this->assertNull(app(AutoReplyPolicy::class)->agentFor($this->currentApp(), $this->currentCompany(), ChannelTypeEnum::SMS));
    }

    public function testTheConfiguredAgentGetsOneRuleOnTheConversationResponder(): void
    {
        $agent = $this->agent();
        $this->currentCompany()->set(AutoReplyPolicy::SETTING, $agent->getId());

        $resolved = app(AutoReplyPolicy::class)->agentFor($this->currentApp(), $this->currentCompany(), ChannelTypeEnum::SMS);
        $this->assertSame($agent->getId(), $resolved?->getId());

        $rule = $this->ensureRule($agent);
        $this->ensureRule($agent);

        $this->assertNotNull($rule);
        $this->assertSame(1, Rule::query()->where('name', $rule->name)->fromCompany($this->currentCompany())->count());
        $this->assertSame(
            [ConversationResponderActivity::class],
            $rule->workflowActivities()->with('activity.action')->get()->pluck('activity.action.model_name')->all()
        );
    }

    /**
     * A second provider joins the same rule: one responder answers both mediums.
     */
    public function testEachProviderAddsItsConversationsToTheOneRule(): void
    {
        $agent = $this->agent();

        $this->ensureRule($agent);
        $rule = $this->ensureRule($agent, '/^other-/', legacyResponder: null);

        $this->assertSame(['/^fake-/', '/^other-/'], $rule->getRulesConditions()->orderBy('id')->pluck('value')->all());
        $this->assertSame('1 or 2', $rule->pattern);
    }

    /**
     * A rule a person set up with the provider's own responder is how this tenant answers today; a
     * second one would answer every message twice.
     */
    public function testATenantAnsweringWithTheProvidersResponderGetsNoSecondRule(): void
    {
        $agent = $this->agent();
        $byHand = $this->ensureRule($agent);
        $byHand->forceFill(['name' => 'Responder (by hand)'])->saveOrFail();
        $current = $byHand->workflowActivities()->firstOrFail()->activity;
        $providersResponder = RuleWorkflowAction::query()->firstOrCreate(
            [
                'system_modules_id' => $current->system_modules_id,
                'actions_id' => app(WorkflowActionCatalog::class)->register(self::LEGACY_RESPONDER)->getId(),
            ],
            ['is_deleted' => 0]
        );
        $byHand->workflowActivities()->update(['rules_workflow_actions_id' => $providersResponder->getId()]);

        $this->assertNull($this->ensureRule($agent));
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
    }

    private function ensureRule(
        Agent $agent,
        string $pattern = '/^fake-/',
        ?string $legacyResponder = self::LEGACY_RESPONDER
    ): ?Rule {
        return new EnsureAutoReplyRuleAction(
            app: $this->currentApp(),
            company: $this->currentCompany(),
            agent: $agent,
            channelSlugPattern: $pattern,
            legacyResponder: $legacyResponder
        )->execute();
    }

    private function inbound(array $communication): Message
    {
        return Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'users_id' => auth()->user()->getId(),
                'message' => array_filter(['content' => 'Hi', 'communication' => $communication ?: null]),
            ]);
    }
}
