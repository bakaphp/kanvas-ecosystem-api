<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Aws\MockHandler;
use Aws\Result;
use Kanvas\Intelligence\Agents\Exceptions\UnknownToolRunsExceededException;
use Kanvas\Intelligence\Agents\Neuron\Nodes\BoundedToolNode;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\UnknownToolStub;
use Kanvas\Intelligence\Agents\Services\BedrockClientService;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-675: a model that called `get_lead_ref` — a name it read in a
 * sibling tool's description but was never granted — killed the whole turn with a ProviderException.
 *
 * The recovery has two halves: the provider answers the parse with a stub so the response loads, and the
 * agent's tool error handler answers the registry miss ToolNode then throws.
 */
final class UnknownToolCallRecoveryTest extends TestCase
{
    private function provider(): KanvasGemini
    {
        return new KanvasGemini(key: 'test-key', model: 'gemini-3.7-flash');
    }

    private function realTool(): Tool
    {
        return new CallbackTool('search_leads', 'Find leads by name.', fn (): string => 'ok');
    }

    /**
     * @return list<string>
     */
    private function declaredToolNames(object $provider): array
    {
        /** @var list<Tool> $tools */
        $tools = new ReflectionProperty($provider, 'tools')->getValue($provider);

        return array_map(static fn (Tool $tool): string => $tool->getName(), $tools);
    }

    public function testAnswersAnUnknownToolCallWithAnErrorInsteadOfKillingTheTurn(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);

        $tool = $provider->findTool('get_lead_ref');
        $tool->setInputs(['lead_id' => 12]);
        $tool->execute();

        $this->assertInstanceOf(UnknownToolStub::class, $tool);

        $result = json_decode((string) $tool->getResult(), true);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no tool named "get_lead_ref"', $result['message']);
        $this->assertStringContainsString('search_leads', $result['message']);
    }

    public function testASecondUnknownCallNeverRecommendsTheFirstStub(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('get_lead_ref');

        $second = $provider->findTool('update_lead_stage');
        $second->setInputs([]);
        $second->execute();

        $message = json_decode((string) $second->getResult(), true)['message'];

        $this->assertStringContainsString('search_leads', $message);
        $this->assertStringNotContainsString('get_lead_ref', $message, 'The earlier stub is declared, not available');
    }

    public function testAStubGivesWayWhenTheRealToolArrives(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('find_person');

        $provider->setTools([$this->realTool(), new CallbackTool('find_person', 'Find one person.', fn (): string => 'ok')]);

        $this->assertSame(
            ['search_leads', 'find_person'],
            $this->declaredToolNames($provider),
            'Gemini rejects a function declared twice, so the stub drops out once the real tool is declared'
        );
    }

    public function testKeepsTheStubDeclaredOnLaterRoundsSoTheProviderAcceptsTheResponse(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('get_lead_ref');

        // Neuron re-sends the agent's own tool list on every inference round. The stub has to survive
        // that, or Gemini gets a functionResponse for a function it never declared.
        $provider->setTools([$this->realTool()]);

        $this->assertSame(['search_leads', 'get_lead_ref'], $this->declaredToolNames($provider));
    }

    public function testStubIsDeclaredAsUnusableSoTheModelStopsCallingIt(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('get_lead_ref');

        $mapper = new ReflectionMethod($provider, 'toolPayloadMapper')->invoke($provider);
        $declarations = $mapper->map(new ReflectionProperty($provider, 'tools')->getValue($provider));

        $stub = $declarations['functionDeclarations'][1];

        $this->assertSame('get_lead_ref', $stub['name']);
        $this->assertStringContainsString('NOT AVAILABLE', $stub['description']);
        $this->assertSame([], $stub['parameters']['required']);
    }

    public function testRealToolsStillResolveAndEveryCallGetsItsOwnCopy(): void
    {
        $provider = $this->provider();
        $real = $this->realTool();
        $provider->setTools([$real]);

        $this->assertSame('search_leads', $provider->findTool('search_leads')->getName());
        $this->assertNotSame($real, $provider->findTool('search_leads'));

        // A repeated unknown call must not hand back the instance carrying the previous call's result.
        $this->assertNotSame(
            $provider->findTool('get_lead_ref'),
            $provider->findTool('get_lead_ref')
        );
    }

    public function testNamesEveryToolTheAgentActuallyHasSoTheModelCanSelfCorrect(): void
    {
        $provider = $this->provider();
        $provider->setTools([
            $this->realTool(),
            new CallbackTool('add_lead_note', 'Write a note.', fn (): string => 'ok'),
        ]);

        $tool = $provider->findTool('get_lead_ref');
        $tool->execute();

        $message = json_decode((string) $tool->getResult(), true)['message'];

        // Pinned whole so the stub can never end up advertising itself back to the model.
        $this->assertStringContainsString('you already know: search_leads, add_lead_note.', $message);
    }

    /**
     * The agent half: ToolNode resolves the call against the agent's registry, where no stub lives,
     * and throws. The error handler turns that into the same feedback instead of ending the turn.
     */
    public function testTheAgentAnswersARegistryMissWithTheSameFeedback(): void
    {
        $provider = new ScriptedToolCallNeuronProvider('get_lead_ref', ['lead_id' => 12]);
        $agent = new CapturingNeuronAgentStub()->setThreadId('unknown-tool');
        $agent->capturedProvider = $provider;
        $agent->addTool($this->realTool());

        $reply = $agent->chat(new UserMessage('Who is lead 12?'))->getMessage();

        $this->assertSame('Review done', $reply?->getContent());

        $sent = array_values(array_filter(
            $provider->secondCallMessages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));

        $this->assertCount(1, $sent);

        $result = json_decode((string) $sent[0]->getToolCalls()[0]->getResult(), true);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no tool named "get_lead_ref"', $result['message']);
        $this->assertStringContainsString('search_leads', $result['message']);
    }

    /**
     * Regression for KANVAS-ECOSYSTEM-70A: a registry miss is never counted by the engine, so a model
     * alternating `get_current_time` with a `nutrition_totals` it was never given ran until the real
     * tool's budget ended the turn, under the real tool's name.
     */
    public function testAModelThatKeepsCallingAToolItWasNeverGivenEndsTheTurnOnThatTool(): void
    {
        $provider = new class () extends FakeNeuronProvider {
            public int $inferences = 0;

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $this->inferences++;
                $tool = $this->inferences % 2 === 1 ? 'get_current_time' : 'nutrition_totals';
                $call = ToolCall::make($tool, 'call-' . $this->inferences, []);

                return $this->respond(new ToolCallMessage(null, [$call]));
            }
        };

        $timeRuns = 0;
        $agent = new CapturingNeuronAgentStub()->setThreadId('unknown-tool-loop');
        $agent->capturedProvider = $provider;
        $agent->addTool(new CallbackTool(
            'get_current_time',
            'The current time.',
            function () use (&$timeRuns): string {
                $timeRuns++;

                return 'noon';
            }
        ));

        try {
            $agent->chat(new UserMessage('I had rice and beans'));
            $this->fail('A model looping on a tool it does not have must not run the turn out');
        } catch (UnknownToolRunsExceededException $e) {
            $this->assertStringContainsString('nutrition_totals', $e->getMessage());
        }

        $this->assertSame(BoundedToolNode::MAX_UNKNOWN_TOOL_ROUNDS + 1, $timeRuns);
        $this->assertSame((BoundedToolNode::MAX_UNKNOWN_TOOL_ROUNDS + 1) * 2, $provider->inferences);
    }

    /**
     * Calls made in one response were all made before the model was told anything, so a batch of them is
     * one strike however many it holds: the model still gets its feedback and the turn goes on.
     */
    public function testSeveralCallsToAMissingToolInOneResponseDoNotEndTheTurn(): void
    {
        $provider = new class () extends FakeNeuronProvider {
            private bool $called = false;

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->called) {
                    return $this->respond(new AssistantMessage('Review done'));
                }

                $this->called = true;

                $calls = array_map(
                    static fn (int $lead): ToolCall => ToolCall::make('get_lead_ref', 'call-' . $lead, []),
                    range(1, BoundedToolNode::MAX_UNKNOWN_TOOL_ROUNDS + 2)
                );

                return $this->respond(new ToolCallMessage(null, $calls));
            }
        };

        $agent = new CapturingNeuronAgentStub()->setThreadId('unknown-tool-batch');
        $agent->capturedProvider = $provider;
        $agent->addTool($this->realTool());

        $reply = $agent->chat(new UserMessage('Summarise these leads'))->getMessage();

        $this->assertSame('Review done', $reply?->getContent());
    }

    /**
     * Regression for KANVAS-ECOSYSTEM-708: a Bedrock model answered with a tool call that carried no
     * name. The call was replayed and its stub declared under that empty name, and the AWS SDK refuses
     * both before the request leaves, so the turn died on its second round.
     */
    #[DataProvider('namesNoProviderDeclares')]
    public function testAToolCalledUnderANameNoProviderDeclaresIsAnsweredUnderOneItAccepts(string $called): void
    {
        $bedrock = new MockHandler([
            $this->bedrockReply([['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => $called, 'input' => []]]]),
            $this->bedrockReply([['text' => 'Review done']]),
        ]);
        $client = BedrockClientService::make('test-key');
        $client->getHandlerList()->setHandler($bedrock);

        $provider = new KanvasBedrock($client, 'us.meta.llama4-scout-17b-instruct-v1:0');
        $provider->setTools([$this->realTool()]);

        $question = new UserMessage('Who is lead 12?');
        $call = $provider->chat($question)->message();

        $this->assertInstanceOf(ToolCallMessage::class, $call);

        $answered = array_map(
            static fn (ToolCall $tool): ToolCall => $tool->setResult(
                UnknownToolStub::response($tool->getName(), ['search_leads'])
            ),
            $call->getToolCalls(),
        );

        // Neuron re-sends the agent's own tool list on every inference round.
        $provider->setTools([$this->realTool()]);
        $reply = $provider->chat($question, $call, new ToolResultMessage($answered))->message();

        $this->assertSame('Review done', $reply->getContent());

        $sent = $bedrock->getLastCommand()->toArray();
        $names = [
            ...array_column(array_column($sent['toolConfig']['tools'], 'toolSpec'), 'name'),
            ...array_column(array_column($sent['messages'][1]['content'], 'toolUse'), 'name'),
        ];

        $this->assertCount(3, $names, 'The real tool, the stub and the replayed call');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/', $name);
        }
        $this->assertSame($names[1], $names[2], 'The call is replayed under the name its stub is declared with');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesNoProviderDeclares(): array
    {
        return [
            'no name' => [''],
            'only spaces' => ['   '],
            'characters outside the grammar' => ['functions.get lead/ref'],
            'opens with a digit' => ['2nd_lookup'],
            'longer than the limit' => [str_repeat('lookup_', 20)],
        ];
    }

    public function testACallWithNoNameIsToldSoInsteadOfBeingQuotedAnEmptyName(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);

        $call = $provider->newToolCall('', 'call-1', []);
        $message = UnknownToolStub::response($call->getName(), ['search_leads'])['message'];

        $this->assertStringContainsString('without a tool name', $message);
        $this->assertStringContainsString('search_leads', $message);
    }

    public function testARealToolIsNeverRenamedWhateverItsNameLooksLike(): void
    {
        $provider = $this->provider();
        $provider->setTools([new CallbackTool('crm.search-leads', 'Find leads by name.', fn (): string => 'ok')]);

        $this->assertSame('crm.search-leads', $provider->newToolCall('crm.search-leads', 'call-1', [])->getName());
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function bedrockReply(array $content): Result
    {
        return new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => $content]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ]);
    }
}
