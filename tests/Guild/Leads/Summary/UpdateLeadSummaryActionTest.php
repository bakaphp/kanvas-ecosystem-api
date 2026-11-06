<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Summary\Actions\UpdateLeadSummaryAction;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Guild\Leads\Summary\Output\CommitmentOperation;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;
use Kanvas\Guild\Leads\Summary\Output\MessageCitation;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use Tests\Stubs\Intelligence\ScriptedStructuredNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

final class UpdateLeadSummaryActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    /**
     * Without `anyOf` Neuron describes every array property as a list of strings, the model answers with
     * strings and the objects it should have sent never exist.
     */
    public function testTheSchemaAsksForObjectsAndTheAnswerDeserializesIntoThem(): void
    {
        $schema = new JsonSchema()->generate(LeadSummaryUpdate::class);
        $operation = $schema['properties']['operations']['items'];

        $this->assertSame('object', $operation['type']);
        $this->assertSame('object', $operation['properties']['citations']['items']['type']);
        $this->assertArrayHasKey('message_id', $operation['properties']['citations']['items']['properties']);

        $update = new Deserializer()->fromJson($this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Send photos', 'citations' => [7]]]), LeadSummaryUpdate::class);

        $this->assertInstanceOf(CommitmentOperation::class, $update->operations[0]);
        $this->assertInstanceOf(MessageCitation::class, $update->operations[0]->citations[0]);
        $this->assertSame(7, $update->operations[0]->citations[0]->message_id);
    }

    public function testAnUpdateAddsCitedItemsAndCoversItsBatch(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'Can you send me photos of the Tacoma?');
        $promise = $this->leadMessage($lead, 'Sure, I will send them tomorrow morning', fromAgent: true);

        $progress = $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Send Tacoma photos', 'citations' => [$promise->getId()]]])]);

        $this->assertTrue($progress->saved);
        $state = LeadSummaryRepository::state($lead);
        $this->assertSame($promise->getId(), $state->through);
        $this->assertSame('Send Tacoma photos', $state->openItems()[0]['text']);
        $this->assertSame([$promise->getId()], $state->openItems()[0]['citations']);
        $this->assertStringContainsString('Send Tacoma photos', (string) LeadSummaryRepository::note($lead)->getMessage()['content']);
    }

    public function testReplaceClosesTheOldItemAndLinksItsSuccessor(): void
    {
        $lead = $this->makeLead();
        $first = $this->leadMessage($lead, 'Staff: send him to the Select lot');
        $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'add', 'kind' => 'instruction', 'text' => 'Send to the Select lot', 'citations' => [$first->getId()]]])]);
        $itemId = LeadSummaryRepository::state($lead)->openItems()[0]['id'];

        $correction = $this->leadMessage($lead, 'Staff: correction, use the CDJR lot instead');
        $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'replace', 'item_id' => $itemId, 'text' => 'Send to the CDJR lot', 'citations' => [$correction->getId()]]])]);

        $state = LeadSummaryRepository::state($lead);
        $old = $state->findItem($itemId);
        $this->assertSame(LeadSummaryState::REPLACED, $old['status']);
        $this->assertSame([$correction->getId()], $old['closed_by']);
        $this->assertSame([$first->getId()], $old['citations']);

        $open = $state->openItems();
        $this->assertCount(1, $open);
        $this->assertSame('Send to the CDJR lot', $open[0]['text']);
        $this->assertSame($itemId, $open[0]['replaces']);
        $this->assertSame($open[0]['id'], $old['replaced_by']);
    }

    public function testAnItemTheModelLeavesOutStaysOpen(): void
    {
        $lead = $this->makeLead();
        $promise = $this->leadMessage($lead, 'I will hold the truck until Friday', fromAgent: true);
        $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Hold the truck until Friday', 'citations' => [$promise->getId()]]])]);

        $later = $this->leadMessage($lead, 'ok thanks');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $state = LeadSummaryRepository::state($lead);
        $this->assertSame($later->getId(), $state->through);
        $this->assertSame('Hold the truck until Friday', $state->openItems()[0]['text']);
    }

    public function testAnUnknownItemRejectsTheWholeUpdate(): void
    {
        $lead = $this->makeLead();
        $first = $this->leadMessage($lead, 'I will call you Monday', fromAgent: true);
        $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'Call Monday', 'citations' => [$first->getId()]]])]);
        $before = LeadSummaryRepository::note($lead)->getMessage();

        $next = $this->leadMessage($lead, 'Also send the price sheet please');
        $bad = $this->summaryAnswer([
            ['op' => 'add', 'kind' => 'commitment', 'text' => 'Send price sheet', 'citations' => [$next->getId()]],
            ['op' => 'resolve', 'item_id' => 'item_doesnotexist', 'citations' => [$next->getId()]],
        ]);
        $provider = new ScriptedStructuredNeuronProvider([$bad, $bad]);
        $progress = new UpdateLeadSummaryAction($lead, $provider, microtime(true) + 15)->execute();

        $this->assertFalse($progress->saved);
        $this->assertSame('rejected', $progress->failure);
        $this->assertSame($before, LeadSummaryRepository::note($lead)->getMessage(), 'Not even the valid add was applied');
        $this->assertCount(2, $provider->prompts, 'One more attempt with the reasons');
        $this->assertStringContainsString('item_doesnotexist, which is not an open item', $provider->prompts[1]);
    }

    public function testACitationOutsideTheBatchRejectsTheUpdate(): void
    {
        $lead = $this->makeLead();
        $otherLead = $this->makeLead();
        $foreign = $this->leadMessage($otherLead, 'another prospect, another promise');
        $this->leadMessage($lead, 'when can I come by?');

        $bad = $this->summaryAnswer([['op' => 'add', 'kind' => 'commitment', 'text' => 'stolen', 'citations' => [$foreign->getId()]]]);
        $progress = $this->updateSummary($lead, [$bad, $bad]);

        $this->assertSame('rejected', $progress->failure);
        $this->assertNull(LeadSummaryRepository::note($lead));
    }

    public function testMalformedOutputWritesNothing(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'hello');

        $progress = $this->updateSummary($lead, ['not json at all', '{"operations": "nope"}']);

        $this->assertFalse($progress->saved);
        $this->assertSame('provider', $progress->failure);
        $this->assertNull(LeadSummaryRepository::note($lead));
    }

    public function testTheSummaryNoteAndTelemetryNeverMakeTheLeadStale(): void
    {
        $lead = $this->makeLead();
        $last = $this->leadMessage($lead, 'see you Saturday');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $telemetry = Message::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withMessageType(MessageTypeService::getOrCreate($lead->app, 'agent'))
            ->create(['message' => ['content' => '', 'tool_calls' => [['name' => 'get_lead']]]]);
        $telemetry->addEntity($lead);

        $provider = new ScriptedStructuredNeuronProvider([]);
        $progress = new UpdateLeadSummaryAction($lead, $provider, microtime(true) + 15)->execute();

        $this->assertSame($last->getId(), $progress->state->through);
        $this->assertNull($progress->failure);
        $this->assertSame([], $provider->prompts, 'Nothing new to summarize, no call');
    }

    public function testAMessageArrivingAfterTheBatchIsLeftForTheNextUpdate(): void
    {
        $lead = $this->makeLead();
        $covered = $this->leadMessage($lead, 'first question');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $late = $this->leadMessage($lead, 'filed during the call');

        $this->assertSame($covered->getId(), LeadSummaryRepository::state($lead)->through);
        $this->assertGreaterThan(LeadSummaryRepository::state($lead)->through, $late->getId());
    }

    /**
     * An edited staff note invalidates what was built on it: coverage restarts there and the item that
     * cites it stays flagged until a rebuild keeps, replaces or resolves it.
     */
    public function testAChangedMessageIsRebuiltAndItsItemsComeBackUnderReview(): void
    {
        $lead = $this->makeLead();
        $note = $this->leadMessage($lead, 'Staff: price locked at 30k');
        $after = $this->leadMessage($lead, 'great');
        $this->updateSummary($lead, [$this->summaryAnswer([['op' => 'add', 'kind' => 'instruction', 'text' => 'Price locked at 30k', 'citations' => [$note->getId()]]])]);
        $itemId = LeadSummaryRepository::state($lead)->openItems()[0]['id'];

        $changed = LeadSummaryRepository::state($lead)->withChangedMessage($note->getId(), '2026-11-05');
        LeadSummaryRepository::save($lead, $changed);
        $this->assertSame($note->getId(), LeadSummaryRepository::state($lead)->rebuildFrom);
        $this->assertStringContainsString('source changed on 2026-11-05', LeadSummaryRepository::state($lead)->render());

        $provider = new ScriptedStructuredNeuronProvider([$this->summaryAnswer([['op' => 'keep', 'item_id' => $itemId, 'citations' => [$note->getId()]]])]);
        new UpdateLeadSummaryAction($lead, $provider, microtime(true) + 15)->execute();

        $state = LeadSummaryRepository::state($lead);
        $this->assertNull($state->rebuildFrom);
        $this->assertSame($after->getId(), $state->through);
        $this->assertArrayNotHasKey('source_changed_at', $state->findItem($itemId));
        $this->assertStringContainsString('(source changed)', $provider->prompts[0]);
    }
}
