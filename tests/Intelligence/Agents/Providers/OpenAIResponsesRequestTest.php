<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAIResponses;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * gpt-6 models answer a /chat/completions request carrying both function tools and a reasoning effort
 * with a 400, so every tool-using agent turn on them failed.
 */
final class OpenAIResponsesRequestTest extends TestCase
{
    public function testToolsAndReasoningEffortGoToTheResponsesEndpointTogether(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'name' => 'search_leads', 'call_id' => 'call_1', 'arguments' => '{"name":"Ana"}'],
                ],
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                    'output_tokens_details' => ['reasoning_tokens' => 25],
                ],
            ])),
        ]);

        $provider = new KanvasOpenAIResponses(
            key: 'test-key',
            model: 'gpt-6.1-sol',
            parameters: ['reasoning_effort' => 'high'],
            httpClient: new GuzzleHttpClient(handler: HandlerStack::create($mock)),
        );
        $provider->setTools([new CallbackTool('search_leads', 'Find leads by name.', fn (): string => 'ok')]);

        $reply = $provider->chat(new UserMessage('Find Ana'))->message();

        $request = $mock->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringEndsWith('/v1/responses', (string) $request->getUri());
        $this->assertSame(['effort' => 'high'], $body['reasoning']);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertFalse($body['store']);
        $this->assertSame('search_leads', $body['tools'][0]['name']);

        $this->assertInstanceOf(ToolCallMessage::class, $reply);
        $this->assertSame('search_leads', $reply->getToolCalls()[0]->getName());
        $this->assertSame(25, $reply->getUsage()->reasoningTokens);
    }
}
