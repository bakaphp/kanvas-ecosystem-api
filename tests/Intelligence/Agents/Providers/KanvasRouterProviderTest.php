<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasRouterProvider;
use Mockery;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

class KanvasRouterProviderTest extends TestCase
{
    #[DataProvider('bedrockFailures')]
    public function testBedrockOnlyFallsBackOnTransientFailures(?int $status, bool $connectionError, bool $shouldFallback): void
    {
        $error = new BedrockRuntimeException('Bedrock failed', new Command('Converse'), [
            'response' => $status !== null ? new Response($status) : null,
            'connection_error' => $connectionError,
        ]);
        $client = Mockery::mock(BedrockRuntimeClient::class);
        $client->shouldReceive('converseAsync')->once()->andReturn(new RejectedPromise($error));
        $router = KanvasRouterProvider::make()
            ->addProvider('primary', new KanvasBedrock($client, 'global.anthropic.claude-haiku-5-5'))
            ->addProvider('fallback', new FakeNeuronProvider('Fallback answered'))
            ->setFallbackOrder('primary', 'fallback');

        if (! $shouldFallback) {
            $this->expectExceptionObject($error);
        }

        $response = $router->chat(new UserMessage('hello'));

        $this->assertSame('Fallback answered', $response->message()->getContent());
    }

    public static function bedrockFailures(): array
    {
        return [
            'throttled' => [429, false, true],
            'server failure' => [500, false, true],
            'unavailable' => [503, false, true],
            'connection failure' => [null, true, true],
            'invalid request' => [400, false, false],
            'invalid credentials' => [401, false, false],
            'access denied' => [403, false, false],
            'non-transport failure' => [null, false, false],
        ];
    }

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
