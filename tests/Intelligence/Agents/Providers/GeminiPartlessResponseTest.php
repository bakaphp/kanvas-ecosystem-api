<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Chat\Messages\AssistantMessage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-691: a Gemini candidate with `content` but no `parts` killed the
 * turn with `Undefined array key "parts"` inside Neuron's HandleChat.
 */
final class GeminiPartlessResponseTest extends TestCase
{
    private function process(array $result): AssistantMessage
    {
        $provider = new KanvasGemini(key: 'test-key', model: 'gemini-3.7-flash');

        return new ReflectionMethod($provider, 'processChatResult')->invoke($provider, $result);
    }

    public function testPartlessStopBecomesAnEmptyReplyInsteadOfCrashing(): void
    {
        $message = $this->process([
            'candidates' => [
                ['content' => ['role' => 'model'], 'finishReason' => 'STOP'],
            ],
            'usageMetadata' => ['promptTokenCount' => 5120],
        ]);

        $this->assertSame('', (string) $message->getContent());
        $this->assertSame('STOP', $message->stopReason());
        $this->assertSame(5120, $message->getUsage()->inputTokens);
    }

    public function testPartlessMaxTokensStillEndsTheTurnEmpty(): void
    {
        $message = $this->process([
            'candidates' => [
                ['content' => ['role' => 'model'], 'finishReason' => 'MAX_TOKENS'],
            ],
        ]);

        $this->assertSame('', (string) $message->getContent());
        $this->assertSame('MAX_TOKENS', $message->stopReason());
    }

    public function testResponsesWithPartsAreUntouched(): void
    {
        $message = $this->process([
            'candidates' => [
                [
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Hola']]],
                    'finishReason' => 'STOP',
                ],
            ],
        ]);

        $this->assertSame('Hola', $message->getContent());
    }
}
