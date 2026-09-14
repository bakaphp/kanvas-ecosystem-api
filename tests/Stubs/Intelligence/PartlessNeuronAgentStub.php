<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use ErrorException;
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Gemini answering with a candidate that carries no `parts`. Neuron guards that for `MAX_TOKENS`
 * only, so every other finish reason reaches `foreach ($content['parts'] ...)` and the warning
 * becomes an ErrorException (KANVAS-ECOSYSTEM-691).
 */
class PartlessNeuronAgentStub extends SalesNeuronAgentStub
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new class () extends FakeNeuronProvider {
            #[Override]
            public function chat(Message ...$messages): Message
            {
                throw new ErrorException('Undefined array key "parts"');
            }

            #[Override]
            public function stream(Message ...$messages): Generator
            {
                throw new ErrorException('Undefined array key "parts"');
            }
        };
    }
}
