<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Chat\Messages\ToolCallMessage;
use ReflectionMethod;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * Gemini keeps the parts' keys when it collects function calls, so a thought part ahead of the call
 * puts the call at key 1 and upstream's `$toolCalls[0]` signature lookup finds nothing; the provider
 * re-indexes before building the message.
 */
class KanvasGeminiThoughtSignatureTest extends TestCase
{
    public function testTheSignatureSurvivesAThoughtPartAheadOfTheCall(): void
    {
        $provider = new KanvasGemini(key: 'test-key', model: 'gemini-3.7-flash');
        $provider->setTools([new CallbackTool('search_leads', 'Find leads.', fn (): string => 'ok')]);

        /** @var ToolCallMessage $message */
        $message = new ReflectionMethod($provider, 'createToolCallMessage')->invoke($provider, [], [
            1 => ['functionCall' => ['name' => 'search_leads', 'args' => []], 'thoughtSignature' => 'sig-123'],
        ]);

        $this->assertSame('sig-123', $message->getMetadata('thought_signature'));
        $this->assertSame('search_leads', $message->getToolCalls()[0]->getName());
    }
}
