<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Summary\Actions\PrepareLeadTurnMemoryAction;
use Kanvas\Guild\Leads\Summary\Jobs\ContinueLeadSummaryJob;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Providers\AIProviderInterface;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\MakesLeadConversations;

/**
 * Resolving the summary provider reads the agent's LLM configuration and fails on an app with no key.
 * That must cost nothing on a turn that needs no catch-up, and never fail the turn when one does.
 */
final class PrepareLeadTurnMemoryActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ContinueLeadSummaryJob::class]);
        Notification::fake();
    }

    public function testATurnWithNothingToCatchUpNeverResolvesTheSummaryProvider(): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'hi, is the Tacoma available?');
        $resolved = 0;

        $memory = $this->prepare(
            $lead,
            [$message],
            [$message],
            function () use (&$resolved): AIProviderInterface {
                $resolved++;

                throw new ValidationException('gemini API key is not configured for this agent or app.');
            },
        )->execute();

        $this->assertSame(0, $resolved);
        $this->assertSame('', $memory->block);
        $this->assertNull(LeadSummaryRepository::note($lead));
    }

    public function testAProviderThatCannotBeResolvedLeavesTheTurnWithTheGapInstead(): void
    {
        $lead = $this->makeLead();
        $dropped = $this->leadMessage($lead, 'Can you hold the white Tacoma for me until Friday?');
        $kept = $this->leadMessage($lead, 'Yes, I will hold it for you until Friday.', fromAgent: true);

        $memory = $this->prepare(
            $lead,
            [$dropped, $kept],
            [$kept],
            static function (): AIProviderInterface {
                throw new ValidationException('gemini API key is not configured for this agent or app.');
            },
        )->execute();

        $this->assertSame([$dropped->getId()], $memory->window->uncovered);
        $this->assertStringContainsString('Earlier messages not yet summarized', $memory->block);
        $this->assertStringContainsString('hold the white Tacoma', $memory->block);
        $this->assertSame(0, $memory->state->coveredThrough());
        Bus::assertDispatched(ContinueLeadSummaryJob::class);
    }

    /**
     * An edited or deleted covered message leaves the summary possibly wrong; that alone has to run the
     * catch-up, whatever the window dropped.
     */
    public function testAPendingRebuildRunsTheCatchUpEvenWhenNothingWasDropped(): void
    {
        $lead = $this->makeLead();
        $first = $this->leadMessage($lead, 'price locked at 30k');
        $second = $this->leadMessage($lead, 'great');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);
        LeadSummaryRepository::save($lead, LeadSummaryRepository::state($lead)->withChangedMessage($first->getId(), '2026-11-06'));
        $summaries = new ScriptedStructuredNeuronProvider([$this->summaryAnswer([], 'rebuilt')]);

        $memory = $this->prepare(
            $lead,
            [$first, $second],
            [$first, $second],
            static fn (): AIProviderInterface => $summaries,
        )->execute();

        $this->assertCount(1, $summaries->prompts, 'The rebuild ran in the turn');
        $this->assertNull($memory->state->rebuildFrom);
        $this->assertSame('rebuilt', $memory->state->sections['current_state']);
        Bus::assertNotDispatched(ContinueLeadSummaryJob::class);
    }

    /**
     * @param list<Message> $loaded
     * @param list<Message> $retained
     */
    private function prepare(
        Lead $lead,
        array $loaded,
        array $retained,
        callable $provider
    ): PrepareLeadTurnMemoryAction {
        $ids = static fn (array $messages): array => array_map(
            static fn (Message $message): string => 'social:' . $message->getId(),
            $messages,
        );

        return new PrepareLeadTurnMemoryAction(
            agent: $this->makeAgentFor(auth()->user()),
            lead: $lead,
            window: ['loaded' => $ids($loaded), 'retained' => $ids($retained)],
            historyBudget: 50_000,
            customerFacing: true,
            summaryProvider: $provider(...),
        );
    }
}
