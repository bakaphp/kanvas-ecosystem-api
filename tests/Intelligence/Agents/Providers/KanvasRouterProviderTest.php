<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasRouterProvider;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

/**
 * The stock router stores the prompt as a string, which Neuron 4's SystemMessage violates on the first
 * turn of every tenant with a fallback LLM config.
 */
class KanvasRouterProviderTest extends TestCase
{
    public function testTheSystemMessageReachesTheRoutedProviderIntact(): void
    {
        $primary = new class () extends FakeNeuronProvider {
            public SystemMessage|string|null $received = null;

            public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
            {
                $this->received = $prompt;

                return $this;
            }
        };
        $instructions = new SystemMessage('Be brief.');

        $reply = KanvasRouterProvider::make()
            ->addProvider('primary', $primary)
            ->setFallbackOrder('primary')
            ->systemPrompt($instructions)
            ->chat(new UserMessage('hi'));

        $this->assertSame('Hola Mundo', $reply->message()->getContent());
        $this->assertSame($instructions, $primary->received, 'The message object, not a flattened string');
    }
}
