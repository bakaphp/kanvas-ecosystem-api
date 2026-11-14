<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Closure;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Listeners\RecordAgentReachOutListener;
use Kanvas\Filesystem\Events\FileAttachedToEntityEvent;
use Kanvas\Filesystem\Events\FileDeletedEvent;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Events\LeadCreatedEvent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Actions\Outreach\ReachOutToNewLeadAction;
use Kanvas\Intelligence\Agents\Events\AgentReachedOutToLeadEvent;
use Kanvas\Intelligence\Agents\Listeners\ReachOutToNewLeadListener;
use Kanvas\Intelligence\Agents\Listeners\RespondOnAiAssistChannelListener;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Listeners\IndexAgentDocumentsListener;
use Kanvas\Intelligence\Knowledge\Listeners\PruneKnowledgeDocumentListener;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;
use Kanvas\Intelligence\Triggers\Listeners\ApplyLeadAiModeListener;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * What an agent does on its own needs no workflow rule: it listens for the domain event, and the
 * agent's own row is the switch.
 */
final class AgentListenersTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        // Agents other tests left on the shared company must not answer for this one.
        Agent::query()
            ->servingCompany($this->kanvasApp, $this->company)
            ->get()
            ->filter(fn (Agent $agent): bool => $agent->reachesOutToNewLeads())
            ->each(fn (Agent $agent) => $agent->switchReachOut(false));
    }

    public function testANewLeadIsReachedOnlyWhenAnAgentHasReachOutSwitchedOn(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->agent();

        LeadCreatedEvent::dispatch($lead);
        $this->assertListenerNotQueued(ReachOutToNewLeadListener::class);

        $reachOut = $this->agent();
        $reachOut->switchReachOut();

        LeadCreatedEvent::dispatch($lead);
        $this->assertListenerQueued(
            ReachOutToNewLeadListener::class,
            fn (LeadCreatedEvent $event): bool => $event->lead->is($lead)
        );
        $this->assertTrue(Agent::forNewLeads($this->kanvasApp, $this->company)->is($reachOut));
    }

    public function testATemplateAgentWithNoCompanyNeverReachesOutForOne(): void
    {
        $this->agent(companyId: 0)->switchReachOut();

        $this->assertNull(Agent::forNewLeads($this->kanvasApp, $this->company));

        $own = $this->agent();
        $own->switchReachOut();

        $this->assertTrue(Agent::forNewLeads($this->kanvasApp, $this->company)->is($own));
    }

    public function testAPausedAgentDoesNotReachOut(): void
    {
        $agent = $this->agent();
        $agent->switchReachOut();
        $agent->forceFill(['is_active' => false])->saveOrFail();

        $this->assertNull(Agent::forNewLeads($this->kanvasApp, $this->company));
    }

    public function testASwitchTurnedOffWhileTheReachOutWaitsReachesNobodyAndStillGivesTheLeadItsAiMode(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        Queue::fake();
        $lead = $this->lead();
        $this->agent();

        new ReachOutToNewLeadListener()->handle(new LeadCreatedEvent($lead));

        Event::assertNotDispatched(AgentReachedOutToLeadEvent::class);
        $this->assertNull($lead->get(AgentReachOutConfigEnum::STATUS->value));
        $this->assertListenerQueued(
            ApplyLeadAiModeListener::class,
            fn (LeadAiTriggeredEvent $event): bool => $event->lead->is($lead)
                && $event->triggerType === TriggersEnum::NEW_LEAD->value
        );
    }

    public function testAnAgentPausedWhileTheReachOutWaitsReachesNobody(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        Queue::fake();
        $lead = $this->lead();
        $agent = $this->agent();
        $agent->switchReachOut();
        $agent->forceFill(['is_active' => false])->saveOrFail();

        new ReachOutToNewLeadListener()->handle(new LeadCreatedEvent($lead));

        Event::assertNotDispatched(AgentReachedOutToLeadEvent::class);
        $this->assertNull($lead->get(AgentReachOutConfigEnum::STATUS->value));
    }

    public function testANewLeadAnAgentReachesOutToGetsItsAiModeFromThatReachOutOnly(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->agent()->switchReachOut();

        LeadCreatedEvent::dispatch($lead);
        LeadAiTriggeredEvent::announceNewLead($lead);

        $this->assertCount(1, Queue::listenersPushed(ReachOutToNewLeadListener::class));
        $this->assertListenerNotQueued(ApplyLeadAiModeListener::class);
    }

    public function testANewLeadNobodyReachesOutToGetsItsAiModeFromTheEvent(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->agent();

        LeadCreatedEvent::dispatch($lead);
        LeadAiTriggeredEvent::announceNewLead($lead);

        $this->assertListenerNotQueued(ReachOutToNewLeadListener::class);
        $this->assertListenerQueued(
            ApplyLeadAiModeListener::class,
            fn (LeadAiTriggeredEvent $event): bool => $event->triggerType === TriggersEnum::NEW_LEAD->value
        );
    }

    public function testAnImportThatAnnouncesTheTriggerItselfStillGetsTheAiMode(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->agent()->switchReachOut();

        LeadAiTriggeredEvent::announce($lead, TriggersEnum::NEW_LEAD->value);

        $this->assertCount(1, Queue::listenersPushed(ApplyLeadAiModeListener::class));
    }

    public function testALeadTypeThatSwitchedTheFirstMessageOffIsSkipped(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        $lead = $this->lead(['internet_first_fu_active_default' => false]);
        $agent = $this->agent();
        $agent->switchReachOut();

        $result = new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('first_message_disabled', $lead->get(AgentReachOutConfigEnum::REASON->value));
        Event::assertNotDispatched(AgentReachedOutToLeadEvent::class);
    }

    public function testTheAgentsOwnLeadSourcesLimitTheReachOutAndConnectorsHearNothingOfASkip(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        $lead = $this->lead();
        $agent = $this->agent();
        $agent->switchReachOut(leadSources: ['a-source-no-lead-has']);

        new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertStringStartsWith(
            'source_not_allowed',
            (string) $lead->get(AgentReachOutConfigEnum::REASON->value)
        );
        Event::assertNotDispatched(AgentReachedOutToLeadEvent::class);
    }

    public function testConnectorsHearOfAReachOutThatSentSomething(): void
    {
        Event::fake([AgentReachedOutToLeadEvent::class]);
        Notification::fake();
        Http::fake();
        $this->company->set(ConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
        $lead = $this->lead();
        $type = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesNeuronAgentStub::class]);
        $agent = $this->agent();
        $agent->forceFill(['agent_type_id' => $type->getId()])->saveOrFail();
        $agent->switchReachOut();

        $result = new ReachOutToNewLeadAction($lead, $agent)->execute();

        $this->assertSame($agent->getId(), $result['agent_id'] ?? null);
        Event::assertDispatched(
            AgentReachedOutToLeadEvent::class,
            fn (AgentReachedOutToLeadEvent $event): bool => $event->agent->is($agent)
                && $event->lead->is($lead)
                && $event->result['agent_id'] === $agent->getId()
        );
    }

    public function testALeadsAiModeIsAppliedWithoutARule(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $this->agent();

        LeadAiTriggeredEvent::announce($lead, TriggersEnum::MANUAL_OFF->value);
        $this->assertListenerQueued(
            ApplyLeadAiModeListener::class,
            fn (LeadAiTriggeredEvent $event): bool => $event->triggerType === TriggersEnum::MANUAL_OFF->value
        );
    }

    public function testALeadOfACompanyNoAgentServesKeepsItsAiModeAsItIs(): void
    {
        Queue::fake();
        $this->agent();
        $companyNoAgentServes = new Companies();
        $companyNoAgentServes->id = (int) Companies::query()->max('id') + 1_000_000;
        $lead = $this->lead();
        $lead->setRelation('company', $companyNoAgentServes);

        LeadAiTriggeredEvent::dispatch($lead, TriggersEnum::MANUAL_OFF->value);

        $this->assertListenerNotQueued(ApplyLeadAiModeListener::class);
    }

    public function testOnlyAPersonsMessageOnTheAiAssistChannelIsAnswered(): void
    {
        Queue::fake();
        $aiAssist = $this->channel(ChannelNameEnum::AI_ASSIST->value);
        $other = $this->channel('WhatsApp');

        $aiAssist->addMessage($this->message(fromHuman: false));
        $other->addMessage($this->message(fromHuman: true));
        $this->assertListenerNotQueued(RespondOnAiAssistChannelListener::class);

        $question = $this->message(fromHuman: true);
        $aiAssist->addMessage($question);
        $this->assertListenerQueued(
            RespondOnAiAssistChannelListener::class,
            fn (ChannelMessageAttachedEvent $event): bool => $event->channel->is($aiAssist)
                && $event->message->is($question)
        );
    }

    public function testAChannelWithNoAgentSessionIsNotAnswered(): void
    {
        $channel = $this->channel(ChannelNameEnum::AI_ASSIST->value);
        $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'AI_ASSIST');

        new RespondOnAiAssistChannelListener()->handle(
            new ChannelMessageAttachedEvent($channel, $this->message(fromHuman: true))
        );

        $this->assertSame(0, $channel->messages()->count());
    }

    public function testAFileAttachedToAnAgentIsIndexedWithoutARule(): void
    {
        Queue::fake();
        $agent = $this->agent();

        FileAttachedToEntityEvent::dispatch($this->lead(), new Filesystem());
        $this->assertListenerNotQueued(IndexAgentDocumentsListener::class);

        FileAttachedToEntityEvent::dispatch($agent, new Filesystem());

        $this->assertListenerQueued(
            IndexAgentDocumentsListener::class,
            fn (FileAttachedToEntityEvent $event): bool => $event->entity->is($agent)
        );
    }

    public function testADeletedFileIsPrunedFromTheKnowledgeBaseWithoutARule(): void
    {
        Queue::fake();
        $file = new Filesystem();
        $file->uuid = 'a-deleted-file';

        FileDeletedEvent::dispatch($file);

        $this->assertListenerQueued(
            PruneKnowledgeDocumentListener::class,
            fn (FileDeletedEvent $event): bool => $event->filesystem->uuid === 'a-deleted-file'
        );
    }

    public function testSalesAssistHearsAReachOut(): void
    {
        Event::fake();

        Event::assertListening(AgentReachedOutToLeadEvent::class, RecordAgentReachOutListener::class);
    }

    /**
     * @param class-string $listener
     * @param (Closure(object): bool)|null $forEvent
     */
    private function assertListenerQueued(string $listener, ?Closure $forEvent = null): void
    {
        $queued = Queue::listenersPushed(
            $listener,
            fn (object $event, CallQueuedListener $job, ?string $queue): bool => $queue === 'workflow'
                && ($forEvent === null || $forEvent($event))
        );

        $this->assertNotEmpty($queued, $listener . ' was not queued on workflow for the event');
    }

    /**
     * @param class-string $listener
     */
    private function assertListenerNotQueued(string $listener): void
    {
        $this->assertEmpty(Queue::listenersPushed($listener), $listener . ' was queued');
    }

    private function agent(?int $companyId = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($companyId ?? $this->company->getId())
            ->create();
    }

    /**
     * @param array<string, mixed> $leadTypeConfig
     */
    private function lead(array $leadTypeConfig = []): Lead
    {
        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->company->getId(),
                'name' => 'Internet ' . uniqid(),
            ],
            ['description' => 'Internet Lead', 'is_active' => 1],
        );
        $type->config = $leadTypeConfig;
        $type->saveOrFail();

        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create(['leads_types_id' => $type->getId()]);
    }

    private function channel(string $name): Channel
    {
        return Channel::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $name,
            'slug' => 'agent-listeners-' . uniqid(),
            'description' => $name,
        ]);
    }

    private function message(bool $fromHuman): Message
    {
        return Message::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['message' => ['content' => 'Where does this lead stand?', 'from_human' => $fromHuman]]);
    }
}
