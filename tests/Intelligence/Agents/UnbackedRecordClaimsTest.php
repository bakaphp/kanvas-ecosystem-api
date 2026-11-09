<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
use Kanvas\Intelligence\Agents\Services\UnbackedRecordClaimsService;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\FabricatingNeuronAgentStub;
use Tests\TestCase;

/**
 * An AR agent once answered "create the invoice" with no tool call and a card for invoice 23026, which never existed.
 */
class UnbackedRecordClaimsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Agent $fabricatingAgent;

    public function test_a_record_nothing_returned_is_reported(): void
    {
        $reply = "Invoice Summary:\nInvoice ID: 23026\nCustomer: Harbor Coffee Roasters";

        $this->assertSame(['23026'], new UnbackedRecordClaimsService()->find($reply, 'quote 3569 created, total 4492.80'));
    }

    public function test_markdown_around_the_id_and_file_names_do_not_hide_a_claim(): void
    {
        $reply = "**New Quote (Quote ID: `3672`):**\n- SOW: [sow-draft-3672-r1.pdf](https://cdn/x.pdf)\n- [quote-draft-3672.pdf](https://cdn/y.pdf)";

        $this->assertSame(['3672'], new UnbackedRecordClaimsService()->find($reply, 'quote 3569'));
    }

    public function test_a_record_a_tool_or_an_earlier_turn_returned_is_backed(): void
    {
        $service = new UnbackedRecordClaimsService();
        $evidence = '{"created":true,"invoice_id":22836} [card shown: Official Legal e-CF Issued #E320000000002]';

        $this->assertSame([], $service->find('Invoice #22836 is waiting for approval.', $evidence));
        $this->assertSame([], $service->find('Its e-NCF E320000000002 was accepted by DGII.', $evidence));
    }

    public function test_amounts_and_dates_are_not_mistaken_for_records(): void
    {
        $reply = 'The invoice for 4,492.80 USD is due on 2026-12-15; the quote total is 1147.99.';

        $this->assertSame([], new UnbackedRecordClaimsService()->find($reply, ''));
    }

    public function test_a_fabricated_reply_is_corrected_before_the_person_sees_it(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::REPLY, FabricatingNeuronAgentStub::CORRECTED];
        $handler = $this->fabricatingHandler();

        $reply = $this->runTurn($handler, 'ok now from this quote create the invoice');

        $this->assertSame(FabricatingNeuronAgentStub::CORRECTED, $reply);
        $requests = $handler->capturedProvider->requests;
        $this->assertCount(2, $requests, 'one correction round');
        $correction = (string) end($requests)[count(end($requests)) - 1]->getContent();
        $this->assertStringContainsString('23026', $correction);
        $this->assertStringContainsString('no tool returned', $correction);
    }

    public function test_a_reply_that_repeats_the_fabrication_reaches_staff_flagged(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::REPLY];

        $reply = $this->runTurn($this->fabricatingHandler(), 'ok now from this quote create the invoice');

        $this->assertStringStartsWith(FabricatingNeuronAgentStub::REPLY, $reply);
        $this->assertStringEndsWith(new UnbackedRecordClaimsService()->warning(['23026']), $reply);
    }

    public function test_a_denial_of_earlier_work_counts_only_when_no_tool_ran(): void
    {
        $service = new UnbackedRecordClaimsService();

        $this->assertSame('hallucinated', $service->unverifiedDenial(FabricatingNeuronAgentStub::RECANT, []));
        $this->assertSame('was never created', $service->unverifiedDenial('Invoice 24084 was never created.', []));
        $this->assertSame('does not exist', $service->unverifiedDenial('That record does not exist in Kanvas.', []));
        $this->assertNull($service->unverifiedDenial(FabricatingNeuronAgentStub::RECANT, [['name' => 'find_invoice']]), 'a lookup backs it');
        $this->assertNull($service->unverifiedDenial(FabricatingNeuronAgentStub::CORRECTED, []), 'owning up to work not yet done is not a denial');
        $this->assertNull($service->unverifiedDenial('Which amount should I invoice: the deposit or the full total?', []));
    }

    public function test_a_completion_declared_with_no_tool_call_counts_only_when_no_tool_ran(): void
    {
        $service = new UnbackedRecordClaimsService();

        $this->assertSame('is now officially recorded', $service->unverifiedCompletion(FabricatingNeuronAgentStub::DECLARED_DONE, []));
        $this->assertSame('has been submitted', $service->unverifiedCompletion('Invoice 24091 has been submitted for approval.', []));
        $this->assertSame('fue emitida', $service->unverifiedCompletion('La factura E310000000012 fue emitida y aceptada.', []));
        $this->assertNull($service->unverifiedCompletion(FabricatingNeuronAgentStub::DECLARED_DONE, [['name' => 'answer_quote']]), 'a tool backs it');
        $this->assertNull($service->unverifiedCompletion('Shall I record the quote as accepted and create the deposit invoice?', []), 'a question declares nothing');
        $this->assertNull($service->unverifiedCompletion('Once the customer accepts, the schedule bills the deposit.', []), 'how it works is not a claim it happened');
        $this->assertNull($service->unverifiedCompletion('The meeting was moved to Friday.', []), 'not about a document');
        $this->assertSame('is now officially recorded', $service->unverifiedStateClaim(FabricatingNeuronAgentStub::DECLARED_DONE, []));
        $this->assertSame('hallucinated', $service->unverifiedStateClaim(FabricatingNeuronAgentStub::RECANT, []));
        $this->assertStringContainsString('nothing was done and nothing was checked', $service->stateClaimCorrection('is now officially recorded'));
        $this->assertStringContainsString('look the records up', $service->stateClaimCorrection('hallucinated'));
        $this->assertSame(UnbackedRecordClaimsService::COMPLETION_WARNING, $service->stateClaimWarning('was issued'));
        $this->assertSame(UnbackedRecordClaimsService::DENIAL_WARNING, $service->stateClaimWarning('does not exist'));
    }

    public function test_a_completion_declared_with_no_tool_call_is_sent_back_to_do_the_work(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::DECLARED_DONE, FabricatingNeuronAgentStub::ACCEPTED_FOR_REAL];
        $handler = $this->fabricatingHandler();

        $reply = $this->runTurn($handler, 'approved quote 3802');

        $this->assertSame(FabricatingNeuronAgentStub::ACCEPTED_FOR_REAL, $reply);
        $requests = $handler->capturedProvider->requests;
        $this->assertCount(2, $requests, 'one correction round');
        $correction = (string) end($requests)[count(end($requests)) - 1]->getContent();
        $this->assertStringContainsString('"is now officially recorded"', $correction);
        $this->assertStringContainsString('nothing was done', $correction);
    }

    public function test_a_completion_repeated_without_a_tool_reaches_staff_flagged(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::DECLARED_DONE];

        $reply = $this->runTurn($this->fabricatingHandler(), 'approved quote 3802');

        $this->assertStringStartsWith(FabricatingNeuronAgentStub::DECLARED_DONE, $reply);
        $this->assertStringEndsWith(UnbackedRecordClaimsService::COMPLETION_WARNING, $reply);
    }

    public function test_a_recant_with_no_tool_call_is_sent_back_to_verify(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::RECANT, FabricatingNeuronAgentStub::VERIFIED];
        $handler = $this->fabricatingHandler();

        $reply = $this->runTurn($handler, 'u sure you created invoice 24084 from quote 3801?');

        $this->assertSame(FabricatingNeuronAgentStub::VERIFIED, $reply);
        $requests = $handler->capturedProvider->requests;
        $this->assertCount(2, $requests, 'one correction round');
        $correction = (string) end($requests)[count(end($requests)) - 1]->getContent();
        $this->assertStringContainsString('"hallucinated"', $correction);
        $this->assertStringContainsString('look the records up', $correction);
    }

    public function test_a_recant_repeated_without_a_lookup_reaches_staff_flagged(): void
    {
        FabricatingNeuronAgentStub::$replies = [FabricatingNeuronAgentStub::RECANT];

        $reply = $this->runTurn($this->fabricatingHandler(), 'u sure you created invoice 24084 from quote 3801?');

        $this->assertStringStartsWith(FabricatingNeuronAgentStub::RECANT, $reply);
        $this->assertStringEndsWith(UnbackedRecordClaimsService::DENIAL_WARNING, $reply);
    }

    private function fabricatingHandler(): FabricatingNeuronAgentStub
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $type = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
            'handler' => FabricatingNeuronAgentStub::class,
        ]);
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'agent_type_id' => $type->getId(),
        ]);
        $handler = new FabricatingNeuronAgentStub();
        $handler->setConfiguration(agent: $agent, user: $user);
        $this->fabricatingAgent = $agent;

        return $handler;
    }

    private function runTurn(FabricatingNeuronAgentStub $handler, string $message): string
    {
        return new RunNeuronChatAction(
            agent: $this->fabricatingAgent,
            session: null,
            message: $message,
            app: app(Apps::class),
            user: auth()->user(),
            handler: $handler,
        )->execute();
    }

    public function test_a_card_replays_as_a_marker_naming_its_record(): void
    {
        $card = '{"version":1,"component":"entity","title":"AR Invoice Created","props":{"id":22836,"type":"deal","title":"x","fields":[]}}';
        $text = "Done.\n\n```kanvas-artifact\n{$card}\n```";

        $this->assertSame("Done.\n\n[card shown: AR Invoice Created #22836]", new ArtifactBlockService()->asHistoryMarkers($text));
    }

    public function test_the_model_reads_markers_while_the_transcript_keeps_the_card(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $sessionUuid = (string) Str::uuid();
        $store = new ConversationMessageStore(
            app: $app,
            company: $company,
            user: $user,
            agentClass: 'Stub\\Agent',
            sessionId: $sessionUuid,
            agent: $agent,
            participant: $user,
        );
        $card = '{"version":1,"component":"entity","title":"AR Invoice Created","props":{"id":22836,"type":"deal","title":"x","fields":[]}}';

        $history = new ChatHistory($store, $sessionUuid, 1_000_000, KanvasHistoryTrimmer::make());
        $history->addMessage(new UserMessage('create the invoice'));
        $history->addMessage(new AssistantMessage("Created.\n\n```kanvas-artifact\n{$card}\n```"));

        $replayed = array_map(fn ($message): string => (string) $message->getContent(), $store->loadAll($sessionUuid));
        $stored = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $store->conversationId($sessionUuid))
            ->where('role', 'assistant')
            ->value('content');

        $this->assertContains("Created.\n\n[card shown: AR Invoice Created #22836]", $replayed);
        $this->assertStringContainsString('```kanvas-artifact', (string) $stored);
    }
}
