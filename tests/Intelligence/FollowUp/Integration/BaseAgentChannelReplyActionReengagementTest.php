<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * Verifies the inbound-reply re-engagement hook in BaseAgentChannelReplyAction.
 *
 * Two cases:
 *   1. Lead exhausted with `agent: ...` reason → resumeFollowUp + `lead.follow_up.resumed`
 *   2. Lead exhausted with `max_retries` (NOT agent-set) → NO resume
 *
 * We don't need a real connector inbound to flow through — we construct
 * AgentChannelResponderAction directly with a faked inbound Message. The
 * base class's constructor is the hook point under test.
 */
class BaseAgentChannelReplyActionReengagementTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testInboundResumesAgentSetExhaustion(): void
    {
        $fixtures = $this->seedFixtures();

        // Set lead state to exhausted with agent: prefix.
        $fixtures['lead']->markFollowUpExhausted('agent: prior_disengagement');
        $fixtures['lead']->refresh();
        $this->assertTrue($fixtures['lead']->isFollowUpExhausted());

        try {
            new AgentChannelResponderAction(
                $fixtures['channel'],
                $fixtures['inbound'],
                $fixtures['agent'],
                $fixtures['session'],
            );
        } catch (Throwable) {
            // Base constructor may throw on AI-mode / un_response guards
            // depending on lead config; the re-engagement hook runs BEFORE
            // those guards, so the state should already be reset.
        }

        $fixtures['lead']->refresh();
        $this->assertFalse($fixtures['lead']->isFollowUpExhausted(), 'Agent-set exhaustion should clear on inbound.');

        $event = Event::query()
            ->where('apps_id', $fixtures['app']->getId())
            ->where('event_type', 'lead.follow_up.resumed')
            ->where('source_entity_id', $fixtures['lead']->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event, 'lead.follow_up.resumed event should be emitted.');
    }

    public function testInboundDoesNotResumeMaxRetriesExhaustion(): void
    {
        $fixtures = $this->seedFixtures();

        // max_retries exhaustion — NOT agent-set, should stay.
        $fixtures['lead']->markFollowUpExhausted('max_retries');
        $fixtures['lead']->refresh();

        try {
            new AgentChannelResponderAction(
                $fixtures['channel'],
                $fixtures['inbound'],
                $fixtures['agent'],
                $fixtures['session'],
            );
        } catch (Throwable) {
            // Same: ignore downstream guard exceptions; we only care about
            // the re-engagement hook's behavior.
        }

        $fixtures['lead']->refresh();
        $this->assertTrue($fixtures['lead']->isFollowUpExhausted(), 'Non-agent exhaustion should persist on inbound.');
    }

    /**
     * @return array{app: Apps, lead: Lead, channel: Channel, inbound: Message, agent: Agent, session: Session}
     */
    private function seedFixtures(): array
    {
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'P',

            'is_default' => 0,
        ]);
        $stage = PipelineStage::create(['pipelines_id' => $pipeline->getId(), 'name' => 'S', 'weight' => 1]);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => 'reengagement-' . $lead->getId()],
            ['name' => 'Test', 'description' => 't', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => [
                    'content' => 'Hello back',
                    'from_me' => false,
                    'chat_jid' => 'test',
                    'raw_data' => ['message' => ['conversation' => 'Hello back']],
                ],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        $inbound->addEntity($lead);
        $channel->addMessage($inbound);

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);

        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => 'TestAgent',
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
        ]);

        $session = Session::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => Lead::class,
            'entity_id' => $lead->getId(),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user' => [],
            'content' => [],
            'is_deleted' => 0,
        ]);

        return compact('app', 'lead', 'channel', 'inbound', 'agent', 'session');
    }
}
