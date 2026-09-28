<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Tools\Tool;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-675: a model that called `get_lead_ref` — a name it read in a
 * sibling tool's description but was never granted — killed the whole turn with a ProviderException.
 */
final class UnknownToolCallRecoveryTest extends TestCase
{
    private function provider(): KanvasGemini
    {
        return new KanvasGemini(key: 'test-key', model: 'gemini-3.7-flash');
    }

    private function realTool(): Tool
    {
        return Tool::make('search_leads', 'Find leads by name.')->setCallable(fn (): string => 'ok');
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

        $result = json_decode($tool->getResult(), true);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('no tool named "get_lead_ref"', $result['message']);
        $this->assertStringContainsString('search_leads', $result['message']);
    }

    public function testKeepsTheStubDeclaredOnLaterRoundsSoTheProviderAcceptsTheResponse(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('get_lead_ref');

        // Neuron's ChatNode re-sends the agent's own tool list on every inference round. The stub has
        // to survive that, or Gemini gets a functionResponse for a function it never declared.
        $provider->setTools([$this->realTool()]);

        $this->assertSame(['search_leads', 'get_lead_ref'], $this->declaredToolNames($provider));
    }

    public function testStubIsDeclaredAsUnusableSoTheModelStopsCallingIt(): void
    {
        $provider = $this->provider();
        $provider->setTools([$this->realTool()]);
        $provider->findTool('get_lead_ref');

        $declarations = $provider->toolPayloadMapper()->map(
            new ReflectionProperty($provider, 'tools')->getValue($provider)
        );

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
            Tool::make('add_lead_note', 'Write a note.')->setCallable(fn (): string => 'ok'),
        ]);

        $tool = $provider->findTool('get_lead_ref');
        $tool->execute();

        $message = json_decode($tool->getResult(), true)['message'];

        // Pinned whole so the stub can never end up advertising itself back to the model.
        $this->assertStringContainsString('you already know: search_leads, add_lead_note.', $message);
    }
}
