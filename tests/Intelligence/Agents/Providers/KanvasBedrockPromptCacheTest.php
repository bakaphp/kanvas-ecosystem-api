<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Providers;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Kanvas\Intelligence\Agents\Services\BedrockClientService;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use ReflectionMethod;
use Tests\Stubs\Intelligence\FakeInstructionsToolHandler;
use Tests\TestCase;

/**
 * Claude on Bedrock caches only what a request marks; unmarked, every tool-loop step pays the full
 * instructions and tool schemas again.
 */
final class KanvasBedrockPromptCacheTest extends TestCase
{
    public function testAClaudeRequestMarksTheInstructionsAndTheEndOfTheConversation(): void
    {
        $payload = $this->payload('global.anthropic.claude-haiku-5-5', [
            new UserMessage('first'),
            new AssistantMessage('answer'),
            new UserMessage('second'),
        ]);

        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['system']));
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['messages'][2]['content']));
        $this->assertSame(2, $this->cachePoints($payload));
    }

    /**
     * The instructions carry the current time, so their marker misses a minute later; the tools sit
     * ahead of them in the cached prefix and keep matching between turns.
     */
    public function testTheToolsAreMarkedOnTheirOwnSoTheyOutliveAChangedPrompt(): void
    {
        $payload = $this->payload(
            'global.anthropic.claude-haiku-5-5',
            [new UserMessage('hello')],
            [new FakeInstructionsToolHandler()],
        );

        $this->assertSame('fake_instructions_tool', $payload['toolConfig']['tools'][0]['toolSpec']['name']);
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['toolConfig']['tools']));
        $this->assertSame(3, $this->cachePoints($payload));
        $this->assertSame(
            0,
            $this->cachePoints($this->payload(
                'global.openai.gpt-6-luna',
                [new UserMessage('hello')],
                [new FakeInstructionsToolHandler()],
            )),
        );
    }

    /**
     * The next turn replays the question without its context, so the prefix it can read has to end
     * before the context, not after it.
     */
    public function testTheQuestionIsMarkedBeforeTheContextThatTravelsWithIt(): void
    {
        $question = new UserMessage([
            new TextContent('what is due this week?'),
            new TextContent("<turn-context>\nCurrent time: 10:15 UTC.\n</turn-context>"),
        ]);

        $payload = $this->payload('global.anthropic.claude-haiku-5-5', [
            new UserMessage('first'),
            new AssistantMessage('answer'),
            $question,
        ]);

        $this->assertSame(
            [
                ['text' => 'what is due this week?'],
                ['cachePoint' => ['type' => 'default']],
                ['text' => "<turn-context>\nCurrent time: 10:15 UTC.\n</turn-context>"],
                ['cachePoint' => ['type' => 'default']],
            ],
            $payload['messages'][2]['content'],
        );
        $this->assertSame([['text' => 'first']], $payload['messages'][0]['content']);
    }

    public function testMarkersStoredWithALongThreadNeverPushTheRequestPastTheProviderLimit(): void
    {
        $messages = [];
        foreach (range(1, 6) as $i) {
            $messages[] = new UserMessage([new TextContent('turn ' . $i)->cache()]);
            $messages[] = new AssistantMessage('answer ' . $i);
        }
        $messages[] = new UserMessage('latest');

        $payload = $this->payload(
            'global.anthropic.claude-haiku-5-5',
            $messages,
            [new FakeInstructionsToolHandler()],
        );

        $this->assertSame(4, $this->cachePoints($payload));
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['toolConfig']['tools']));
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['system']));
        $this->assertSame(['cachePoint' => ['type' => 'default']], end($payload['messages'][12]['content']));
    }

    public function testAModelThatCachesOnItsOwnGetsNoMarker(): void
    {
        $payload = $this->payload('global.openai.gpt-6-luna', [new UserMessage('hello')]);

        $this->assertSame(0, $this->cachePoints($payload));
    }

    /**
     * @param array<int, UserMessage|AssistantMessage> $messages
     * @param array<int, Tool> $tools
     * @return array<string, mixed>
     */
    private function payload(string $model, array $messages, array $tools = []): array
    {
        $provider = new KanvasBedrock(BedrockClientService::make('test-key'), $model);
        $provider->systemPrompt('You are a helpful agent.');
        $provider->setTools($tools);

        return new ReflectionMethod($provider, 'createPayLoad')->invoke($provider, $messages);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cachePoints(array $payload): int
    {
        $count = 0;
        $marked = [
            $payload['toolConfig']['tools'] ?? [],
            $payload['system'] ?? [],
            ...array_column($payload['messages'], 'content'),
        ];

        foreach ($marked as $blocks) {
            foreach ($blocks as $block) {
                $count += isset($block['cachePoint']) ? 1 : 0;
            }
        }

        return $count;
    }
}
