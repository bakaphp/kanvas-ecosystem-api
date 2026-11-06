<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Actions\SummarizeLeadConversationAction;
use Kanvas\Guild\Leads\Summary\Actions\UpdateLeadSummaryAction;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Laravel\Ai\AnonymousAgent;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

final class LeadSummaryLongHistoryTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    /**
     * A conversation past one chunk is covered over several updates, and the first promise survives
     * every rewrite because only an operation can close an item.
     */
    public function testALongConversationIsCoveredChunkByChunkAndTheFirstPromiseSurvives(): void
    {
        $lead = $this->makeLead();
        $promise = $this->leadMessage($lead, 'I promise you a free oil change with the purchase');
        $last = null;
        foreach (range(1, 6) as $i) {
            $last = $this->leadMessage($lead, str_repeat("filler {$i} ", 3_000));
        }

        $answers = [$this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Free oil change with the purchase', 'citations' => [$promise->getId()]]])];
        foreach (range(1, 6) as $i) {
            $answers[] = $this->summaryAnswer([]);
        }
        $provider = new ScriptedStructuredNeuronProvider($answers);

        $updates = 0;
        do {
            $progress = new UpdateLeadSummaryAction($lead, $provider, microtime(true) + 15)->execute();
            $updates++;
        } while ($progress->saved && $updates < 10);

        $state = LeadSummaryRepository::state($lead);
        $this->assertGreaterThan(2, $updates, 'More than one chunk');
        $this->assertSame($last->getId(), $state->through);
        $this->assertSame('Free oil change with the purchase', $state->openItems()[0]['text']);
    }

    public function testTheClosedLeadSummaryLeavesARollingSummaryAlone(): void
    {
        AnonymousAgent::fake(['- would overwrite']);
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'hello');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);
        $this->leadMessage($lead, 'one more');

        $this->assertNull(new SummarizeLeadConversationAction($lead)->execute());
        AnonymousAgent::assertNeverPrompted();
    }
}
