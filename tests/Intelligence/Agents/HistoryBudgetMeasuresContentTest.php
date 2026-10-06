<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

/**
 * The provider's prompt count covers instructions and tool schemas as well as the history. Measured
 * that way, an agent with a large toolset read as over its history budget after one short turn: the
 * previous turn was archived on every turn and the summarizer ran on every tool round.
 */
class HistoryBudgetMeasuresContentTest extends TestCase
{
    public function testAShortHistoryUnderALargePromptIsNeitherTrimmedNorOverBudget(): void
    {
        $reply = new AssistantMessage('Lead #711531 is Mike James.');
        $reply->setUsage(new Usage(48000, 12, 36000));

        $messages = [
            new UserMessage('which one is lead 711531?'),
            $reply,
            new UserMessage('can I get the internal link?'),
        ];

        $trimmer = KanvasHistoryTrimmer::make();
        $kept = $trimmer->trim($messages, 50000);

        $this->assertCount(3, $kept);
        $this->assertLessThan(200, $trimmer->getTotalTokens());
    }
}
