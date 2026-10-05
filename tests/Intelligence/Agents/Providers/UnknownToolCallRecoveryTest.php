<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\UnknownToolStub;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
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
}
