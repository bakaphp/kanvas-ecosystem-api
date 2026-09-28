<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kanvas\Intelligence\Agents\Exceptions\ProviderMalformedToolCallException;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use Tests\TestCase;

final class GeminiMalformedFunctionCallTest extends TestCase
{
    public function testAMalformedCallIsRetriedAndTheRetryAnswers(): void
    {
        $mock = new MockHandler([
            $this->malformed(),
            $this->answer('Blocker resolved.'),
        ]);

        $reply = $this->provider($mock)->chat(new UserMessage('Unblock plan 399'));

        $this->assertSame('Blocker resolved.', $reply->getContent());
        $this->assertSame(0, $mock->count());
    }

    public function testAMalformedCallThatPersistsFailsWithoutSalvagingTheNarratedAction(): void
    {
        $mock = new MockHandler([
            $this->malformed(),
            $this->malformed(),
            $this->answer('never requested'),
        ]);

        try {
            $this->provider($mock)->chat(new UserMessage('Unblock plan 399'));
            $this->fail('Expected the malformed call to fail the turn.');
        } catch (ProviderMalformedToolCallException $e) {
            $this->assertStringContainsString('prompt tokens 60641', $e->getMessage());
        }

        $this->assertSame(1, $mock->count());
    }

    public function testMalformedCallsWithPartsAreStillRetried(): void
    {
        foreach ([[], [['text' => 'I have attached the file.']]] as $parts) {
            $mock = new MockHandler([
                $this->malformed(['role' => 'model', 'parts' => $parts]),
                $this->answer('Retry succeeded.'),
            ]);

            $reply = $this->provider($mock)->chat(new UserMessage('Attach the file'));

            $this->assertSame('Retry succeeded.', $reply->getContent());
            $this->assertSame(0, $mock->count());
        }
    }

    public function testOtherProviderFailuresAreNotRetried(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['error' => ['message' => 'Invalid request']])),
            $this->answer('never requested'),
        ]);

        try {
            $this->provider($mock)->chat(new UserMessage('Hello'));
            $this->fail('Expected the provider error to propagate.');
        } catch (ProviderException $e) {
            $this->assertSame('Gemini API Error: Invalid request', $e->getMessage());
        }

        $this->assertSame(1, $mock->count());
    }

    private function provider(MockHandler $mock): KanvasGemini
    {
        return new KanvasGemini(
            key: 'test-key',
            model: 'gemini-2.5-pro',
            httpClient: new GuzzleHttpClient(handler: HandlerStack::create($mock)),
        );
    }

    private function malformed(?array $content = null): Response
    {
        return new Response(200, [], json_encode([
            'candidates' => [
                [
                    'finishReason' => 'MALFORMED_FUNCTION_CALL',
                    'index' => 0,
                    'finishMessage' => "Malformed function call: I've attached architecture-summary.md to Plan #399.",
                    ...($content === null ? [] : ['content' => $content]),
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 60641, 'totalTokenCount' => 60641],
        ]));
    }

    private function answer(string $text): Response
    {
        return new Response(200, [], json_encode([
            'candidates' => [
                ['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP'],
            ],
        ]));
    }
}
