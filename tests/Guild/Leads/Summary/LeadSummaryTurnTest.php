<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Jobs\ContinueLeadSummaryJob;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\Notifications\LeadMemoryGapNotification;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\LeadSummaryCustomerAgentStub;
use Tests\Stubs\Intelligence\LeadSummaryInternalAgentStub;
use Tests\Stubs\Intelligence\LeadSummaryStubProviders;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\MakesLeadConversations;

/**
 * The summary has to be current on the first request of the turn, not the next one: the window drops a
 * promise, the catch-up folds it in, and the instructions of that same request already carry it.
 */
final class LeadSummaryTurnTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;
    use MakesAgents;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    private const string ASK = 'Can you hold the white Tacoma for me until Friday? I really want that one, please.';
    private const string PROMISE = 'Yes, I will hold the white Tacoma for you until Friday, you have my word on it.';

    protected function setUp(): void
    {
        parent::setUp();

        LeadSummaryStubProviders::reset();
        Bus::fake([ContinueLeadSummaryJob::class]);
        Notification::fake();
    }

    public function testADroppedPromiseIsInTheFirstRequestsInstructions(): void
    {
        $lead = $this->makeLead();
        [, $promise] = $this->olderConversation($lead);
        LeadSummaryStubProviders::$summaries = new ScriptedStructuredNeuronProvider([
            $this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Hold the white Tacoma until Friday', 'citations' => [$promise->getId()]]], 'Customer wants the white Tacoma'),
        ]);

        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'is it still on?');

        $instructions = (string) LeadSummaryStubProviders::$chats->instructions;
        $this->assertStringContainsString('What you know about this lead from earlier', $instructions);
        $this->assertStringContainsString('Hold the white Tacoma until Friday', $instructions);
        $this->assertStringNotContainsString('not yet summarized', $instructions, 'Covered, so no gap');
        $this->assertGreaterThanOrEqual($promise->getId(), LeadSummaryRepository::state($lead)->through);
        Bus::assertNotDispatched(ContinueLeadSummaryJob::class);
    }

    public function testACustomerFacingAgentGetsTheMissingMessagesWhenCatchUpFails(): void
    {
        $lead = $this->makeLead();
        $this->olderConversation($lead);
        LeadSummaryStubProviders::$summaries = new ScriptedStructuredNeuronProvider(['not json', 'still not json']);

        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'is it still on?');

        $instructions = (string) LeadSummaryStubProviders::$chats->instructions;
        $this->assertStringContainsString('Earlier messages not yet summarized', $instructions);
        $this->assertStringContainsString(self::PROMISE, $instructions, 'The promise itself, verbatim');
        $this->assertStringContainsString('Agent: ' . self::PROMISE, $instructions, 'Labelled with its speaker');
        $this->assertLessThan(strpos($instructions, self::PROMISE), strpos($instructions, self::ASK), 'Oldest first');
        $this->assertStringContainsString('Do not confirm a price, an appointment or a promise', $instructions);
        $this->assertStringNotContainsString('read_lead_activity', $instructions, 'That tool refuses customer surfaces');
        Bus::assertDispatched(ContinueLeadSummaryJob::class);
        Notification::assertSentToTimes($lead->owner, LeadMemoryGapNotification::class, 1);

        LeadSummaryStubProviders::$summaries = new ScriptedStructuredNeuronProvider(['not json', 'still not json']);
        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'hello?');

        Notification::assertSentToTimes($lead->owner, LeadMemoryGapNotification::class, 1);
    }

    public function testAnInternalAgentIsToldTheDatesAndTheToolInstead(): void
    {
        $lead = $this->makeLead();
        $this->olderConversation($lead);
        LeadSummaryStubProviders::$summaries = new ScriptedStructuredNeuronProvider(['not json', 'still not json']);

        $this->chat(new LeadSummaryInternalAgentStub(), $lead, 'what did we promise him?');

        $instructions = (string) LeadSummaryStubProviders::$chats->instructions;
        $this->assertStringContainsString("Part of this lead's history is not in view", $instructions);
        $this->assertStringContainsString('read_lead_activity', $instructions);
        $this->assertStringNotContainsString(self::PROMISE, $instructions);
        Notification::assertNothingSent();
    }

    /**
     * A customer-facing agent never loads staff notes into its window, so a note is never "dropped";
     * it still has to reach the summary, which is the only way the instruction reaches the agent.
     */
    public function testAStaffNoteTheCustomerAgentNeverSeesStillReachesItsSummary(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'hi, is the Tacoma available?');
        $note = Message::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withMessageType(MessageTypeService::getOrCreate($lead->app, 'notes'))
            ->create(['users_id' => auth()->user()->getId(), 'message' => ['content' => 'Staff: send him to the CDJR lot, not Select']]);
        $note->addEntity($lead);
        LeadSummaryStubProviders::$window = 50_000;
        $summaries = new ScriptedStructuredNeuronProvider([
            $this->summaryAnswer([['op' => 'add', 'kind' => 'instruction', 'text' => 'Send him to the CDJR lot, not Select', 'citations' => [$note->getId()]]]),
        ]);
        LeadSummaryStubProviders::$summaries = $summaries;

        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'where do I go?');

        $this->assertCount(1, $summaries->prompts, 'The note is uncovered even though the window never loaded it');
        $this->assertStringContainsString('send him to the CDJR lot', $summaries->prompts[0]);
        $this->assertStringContainsString('Send him to the CDJR lot, not Select', (string) LeadSummaryStubProviders::$chats->instructions);
    }

    /**
     * D1: a short message followed by silence is history, not work.
     */
    public function testAShortConversationThatFitsCostsNoSummaryCall(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'hi, is the Tacoma available?');
        $summaries = new ScriptedStructuredNeuronProvider([]);
        LeadSummaryStubProviders::$summaries = $summaries;
        LeadSummaryStubProviders::$window = 50_000;

        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'hello');

        $this->assertSame([], $summaries->prompts);
        $this->assertNull(LeadSummaryRepository::note($lead));
        Bus::assertNotDispatched(ContinueLeadSummaryJob::class);
        $this->assertStringNotContainsString('from earlier', (string) LeadSummaryStubProviders::$chats->instructions);
    }

    /**
     * D1: nothing dropped yet, but the uncovered history is past 80% of the budget; the older portion is
     * summarized before it can be cut, the recent tail is left alone.
     */
    public function testTokenPressureSummarizesBeforeTheWindowCuts(): void
    {
        $lead = $this->makeLead();
        $messages = [];
        foreach (range(1, 13) as $i) {
            $messages[] = $this->leadMessage($lead, str_repeat("turn {$i} ", 40), fromAgent: $i % 2 === 0);
        }
        LeadSummaryStubProviders::$window = 1_100;
        $summaries = new ScriptedStructuredNeuronProvider([$this->summaryAnswer([], 'long talk')]);
        LeadSummaryStubProviders::$summaries = $summaries;

        $this->chat(new LeadSummaryCustomerAgentStub(), $lead, 'and?');

        $this->assertCount(1, $summaries->prompts, 'One refresh, before the reply');
        $this->assertStringNotContainsString('not yet summarized', (string) LeadSummaryStubProviders::$chats->instructions);
        $this->assertGreaterThanOrEqual($messages[1]->getId(), LeadSummaryRepository::state($lead)->through);
    }

    private function chat(KanvasGenericNeuronAgent $agent, Lead $lead, string $text): void
    {
        $type = AgentType::factory()->withAppId($lead->apps_id)->create(['provider' => 'neuron', 'handler' => $agent::class]);
        $agent->setConfiguration(agent: $this->makeAgentFor(auth()->user(), $type), entity: $lead, user: auth()->user());
        $agent->setThreadId((string) $lead->uuid);
        $agent->chat(new UserMessage($text));
    }

    /**
     * Two exchanges, then a long tail that pushes them out of a 40-token window.
     *
     * @return array{Message, Message}
     */
    private function olderConversation(Lead $lead): array
    {
        $ask = $this->leadMessage($lead, self::ASK);
        $promise = $this->leadMessage($lead, self::PROMISE, fromAgent: true);
        $this->leadMessage($lead, 'Great, I also want to ask about financing options for it, what rates do you offer?');
        $this->leadMessage($lead, 'We work with several banks, rates start around six percent for good credit profiles.', fromAgent: true);

        return [$ask, $promise];
    }
}
