<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\ChatHistory\RebuildsTrimmedHistory;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use Override;

/**
 * Exercises RebuildsTrimmedHistory on its own. The three real histories differ only in where the rows
 * come from, so the rebuild they share is testable with no DB, no app and no provider — they wire
 * load() and addMessage() to the trait exactly as this does.
 */
class LoadedChatHistoryStub extends AbstractChatHistory
{
    use RebuildsTrimmedHistory;

    /**
     * @param list<Message> $messages
     */
    public function load(array $messages): void
    {
        $this->applyLoadedHistory($messages);
    }

    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        return $this->mergeOrAppend($message);
    }
}
