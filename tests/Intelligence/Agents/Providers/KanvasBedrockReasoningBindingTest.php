<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Mockery;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use ReflectionMethod;
use Tests\Stubs\Intelligence\FakeInstructionsToolHandler;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

/**
 * Claude signs each thinking block with the request that produced it: the instructions, the tools and
 * every message before it. Replayed under anything else the whole request is refused
 * (KANVAS-ECOSYSTEM-704, a tool search that loaded a tool between two rounds of one turn).
 */
final class KanvasBedrockReasoningBindingTest extends TestCase
{
    private const string MODEL = 'global.anthropic.claude-haiku-5-5';

    public function testReasoningIsReplayedWhileTheRequestThatProducedItIsUnchanged(): void
    {
        $provider = $this->provider(rounds: 1);
        $question = new UserMessage('how many deals closed this week?');

        $call = $provider->chat($question)->message();
        $payload = $this->payload($provider, [$question, $call, $this->resultOf('call-1')]);

        $this->assertSame(
            ['reasoningContent', 'toolUse'],
            $this->blockTypes($payload['messages'][1]),
        );
    }

    public function testReasoningIsLeftOutOnceTheToolListChanged(): void
    {
        $provider = $this->provider(rounds: 1);
        $question = new UserMessage('how many deals closed this week?');

        $call = $provider->chat($question)->message();
        $provider->setTools([new FakeInstructionsToolHandler(), $this->toolLoadedMidTurn()]);
        $payload = $this->payload($provider, [$question, $call, $this->resultOf('call-1')]);

        $this->assertSame(['toolUse'], $this->blockTypes($payload['messages'][1]));
    }

    /**
     * The round after the change is signed against the conversation as it was sent, the earlier block
     * already gone, so it stays valid on the rounds that follow.
     */
    public function testReasoningProducedAfterTheChangeIsStillReplayed(): void
    {
        $provider = $this->provider(rounds: 2);
        $question = new UserMessage('how many deals closed this week?');

        $first = $provider->chat($question)->message();
        $provider->setTools([new FakeInstructionsToolHandler(), $this->toolLoadedMidTurn()]);
        $second = $provider->chat($question, $first, $this->resultOf('call-1'))->message();

        $payload = $this->payload($provider, [
            $question,
            $first,
            $this->resultOf('call-1'),
            $second,
            $this->resultOf('call-2'),
        ]);

        $this->assertSame(['toolUse'], $this->blockTypes($payload['messages'][1]));
        $this->assertSame(['reasoningContent', 'toolUse'], $this->blockTypes($payload['messages'][3]));
    }

    public function testReasoningIsLeftOutOnceTheInstructionsChanged(): void
    {
        $provider = $this->provider(rounds: 1);
        $question = new UserMessage('how many deals closed this week?');

        $call = $provider->chat($question)->message();
        $provider->systemPrompt('You are a helpful agent. A tool was loaded.');
        $payload = $this->payload($provider, [$question, $call, $this->resultOf('call-1')]);

        $this->assertSame(['toolUse'], $this->blockTypes($payload['messages'][1]));
    }

    /**
     * The transcript keeps a row's redacted reasoning in its metadata, and the next turn replays the
     * question without the context it travelled with: that block belongs to a request that is gone.
     */
    public function testReasoningReloadedFromAnEarlierTurnIsLeftOut(): void
    {
        $provider = $this->provider(rounds: 0);
        $stored = new AssistantMessage('Three deals closed.');
        $stored->addMetadata('aws_redacted_reasoning', [0 => base64_encode('opaque')]);

        $payload = $this->payload($provider, [
            new UserMessage('how many deals closed this week?'),
            $stored,
            new UserMessage('and last week?'),
        ]);

        $this->assertSame(['text'], $this->blockTypes($payload['messages'][1]));
    }

    private function provider(int $rounds): KanvasBedrock
    {
        $round = 0;
        $client = Mockery::mock(BedrockRuntimeClient::class);
        $client->shouldReceive('converseAsync')->times($rounds)->andReturnUsing(
            static function () use (&$round) {
                $round++;
                $thinking = ['reasoningText' => ['text' => '', 'signature' => 'signature-' . $round]];
                $call = [
                    'toolUseId' => 'call-' . $round,
                    'name' => 'fake_instructions_tool',
                    'input' => ['q' => 'deals'],
                ];

                return Create::promiseFor(new Result([
                    'output' => ['message' => ['content' => [
                        ['reasoningContent' => $thinking],
                        ['toolUse' => $call],
                    ]]],
                    'stopReason' => 'tool_use',
                    'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
                ]));
            }
        );

        $provider = new KanvasBedrock($client, self::MODEL);
        $provider->systemPrompt('You are a helpful agent.');
        $provider->setTools([new FakeInstructionsToolHandler()]);

        return $provider;
    }

    private function toolLoadedMidTurn(): CallbackTool
    {
        return new CallbackTool('loaded_by_tool_search', 'Loaded mid-turn', static fn (): string => 'ok');
    }

    private function resultOf(string $callId): ToolResultMessage
    {
        return new ToolResultMessage([
            ToolCall::make('fake_instructions_tool', $callId, ['q' => 'deals'])->setResult('3'),
        ]);
    }

    /**
     * @param list<Message> $messages
     * @return array<string, mixed>
     */
    private function payload(KanvasBedrock $provider, array $messages): array
    {
        return new ReflectionMethod($provider, 'createPayLoad')->invoke($provider, $messages);
    }

    /**
     * @param array<string, mixed> $message
     * @return list<string>
     */
    private function blockTypes(array $message): array
    {
        $types = array_map(array_key_first(...), $message['content']);

        return array_values(array_filter(
            $types,
            static fn (string $type): bool => $type !== 'cachePoint',
        ));
    }
}
