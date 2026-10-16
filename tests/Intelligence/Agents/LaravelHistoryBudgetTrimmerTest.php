<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\ChatHistory\LaravelHistoryBudgetTrimmer;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use PHPUnit\Framework\TestCase;

class LaravelHistoryBudgetTrimmerTest extends TestCase
{
    public function testAHistoryWithinBudgetIsUntouched(): void
    {
        $history = [new UserMessage('hi'), new AssistantMessage('hello'), new UserMessage('bye')];

        $this->assertSame($history, LaravelHistoryBudgetTrimmer::trim($history, 1_000));
    }

    public function testOldestTurnsGoFirstAndTheCutLandsOnAHumanTurn(): void
    {
        $history = [
            new UserMessage(str_repeat('a', 400)),
            new AssistantMessage(str_repeat('b', 400)),
            new UserMessage(str_repeat('c', 400)),
            new AssistantMessage(str_repeat('d', 400)),
        ];

        // 400 chars = 100 tokens each; 250 tokens fits the last two and a bit of the second.
        $trimmed = LaravelHistoryBudgetTrimmer::trim($history, 250);

        $this->assertCount(2, $trimmed);
        $this->assertSame('user', $trimmed[0]->role->value);
        $this->assertSame(str_repeat('c', 400), $trimmed[0]->content);
    }

    public function testAToolResultNeverOpensTheHistory(): void
    {
        $call = new ToolCall('c1', 'search', ['q' => 'x']);
        $history = [
            new UserMessage(str_repeat('a', 400)),
            new AssistantMessage('', new Collection([$call])),
            new ToolResultMessage(new Collection([new ToolResult('c1', 'search', ['q' => 'x'], str_repeat('r', 200))])),
            new AssistantMessage(str_repeat('d', 400)),
            new UserMessage(str_repeat('e', 400)),
            new AssistantMessage(str_repeat('f', 400)),
        ];

        // Budget covers the last three (f, e, d) and the tool result, but not the call or the first prompt.
        $trimmed = LaravelHistoryBudgetTrimmer::trim($history, 390);

        $this->assertSame('user', $trimmed[0]->role->value);
        $this->assertNotInstanceOf(ToolResultMessage::class, $trimmed[0]);
        $this->assertSame(str_repeat('e', 400), $trimmed[0]->content);
        $this->assertCount(2, $trimmed);
    }

    public function testTheNewestExchangeSurvivesEvenWhenItAloneIsOverBudget(): void
    {
        $history = [
            new UserMessage(str_repeat('a', 400)),
            new AssistantMessage(str_repeat('b', 400)),
            new UserMessage('short'),
            new AssistantMessage(str_repeat('x', 4_000)),
        ];

        $trimmed = LaravelHistoryBudgetTrimmer::trim($history, 10);

        $this->assertCount(2, $trimmed);
        $this->assertSame('short', $trimmed[0]->content);
        $this->assertSame(str_repeat('x', 4_000), $trimmed[1]->content);
    }

    public function testToolCallsAndResultsCountTowardsTheEstimate(): void
    {
        $plain = new AssistantMessage('');
        $withCall = new AssistantMessage('', new Collection([new ToolCall('c1', 'search', ['q' => str_repeat('x', 400)])]));

        $this->assertSame(0, LaravelHistoryBudgetTrimmer::tokens($plain));
        $this->assertGreaterThan(100, LaravelHistoryBudgetTrimmer::tokens($withCall));
    }

    public function testAnEmptyHistoryStaysEmpty(): void
    {
        $this->assertSame([], LaravelHistoryBudgetTrimmer::trim([], 100));
        $this->assertSame([], LaravelHistoryBudgetTrimmer::trim([new Message('assistant', 'orphan')], 100));
    }
}
