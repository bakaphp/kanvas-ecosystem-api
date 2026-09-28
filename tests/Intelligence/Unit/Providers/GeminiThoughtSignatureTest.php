<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A Gemini 3 thinking model signs every `functionCall` and rejects the next request without it. The
 * signature is read off `$toolCalls[0]`, but a thinking model emits its thought part first, so the
 * call is at index 1 — which is why this is intermittent rather than total.
 */
class GeminiThoughtSignatureTest extends TestCase
{
    public function testTheSignatureSurvivesWhenTheCallIsNotTheFirstPart(): void
    {
        // The normal Gemini 3 shape: the model thinks out loud, then calls a tool.
        $message = $this->toolCallMessage([
            1 => [
                'functionCall' => ['name' => 'render_artifact', 'args' => ['title' => 'Summary']],
                'thoughtSignature' => 'SIGNATURE-FROM-GEMINI',
            ],
        ]);

        $this->assertSame('SIGNATURE-FROM-GEMINI', $message->getMetadata('thought_signature'));
    }

    public function testTheSignatureStillSurvivesWhenTheCallIsTheFirstPart(): void
    {
        $message = $this->toolCallMessage([
            0 => [
                'functionCall' => ['name' => 'render_artifact', 'args' => []],
                'thoughtSignature' => 'SIGNATURE-FROM-GEMINI',
            ],
        ]);

        $this->assertSame('SIGNATURE-FROM-GEMINI', $message->getMetadata('thought_signature'));
    }

    /** A model that sends no signature must not gain an invented one. */
    public function testNoSignatureIsInventedWhenGeminiSendsNone(): void
    {
        $message = $this->toolCallMessage([
            1 => ['functionCall' => ['name' => 'render_artifact', 'args' => []]],
        ]);

        $this->assertNull($message->getMetadata('thought_signature'));
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls as `array_filter` leaves them: keys preserved
     */
    private function toolCallMessage(array $toolCalls): ToolCallMessage
    {
        $provider = new KanvasGemini('irrelevant-key', 'gemini-3.5-flash');
        $provider->setTools([Tool::make('render_artifact', 'Render something for the user.')]);

        $create = new ReflectionMethod($provider, 'createToolCallMessage');

        /** @var ToolCallMessage $message */
        $message = $create->invoke($provider, [], $toolCalls);

        return $message;
    }
}
