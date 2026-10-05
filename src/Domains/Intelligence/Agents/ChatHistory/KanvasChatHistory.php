<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Kanvas\Intelligence\Agents\Neuron\Stores\KanvasMessageStore;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use Override;

use const PHP_INT_MAX;

/**
 * Archives by identity instead of by count: KanvasHistoryTrimmer folds same-role turns, which shortens
 * the list without dropping anything, so the stock count would stamp the wrong rows. A mirror of the
 * vendor addMessage() apart from that; re-check it on a Neuron bump.
 */
class KanvasChatHistory extends ChatHistory
{
    #[Override]
    public function addMessage(Message $message): self
    {
        $messages = $this->getMessages();

        foreach ($messages as $present) {
            if ($present->getId() === $message->getId()) {
                return $this;
            }
        }

        $messages[] = $message;
        $trimmed = $this->trimmer->trim($messages, $this->contextWindow);

        $this->store->append($this->threadId, $message);

        // Compared as row ids: the store strips Neuron's prefix when it persists, and the fold recorded
        // the prefixed one, so a folded-in message would otherwise read as dropped and lose its row.
        $dropped = array_values(array_diff(
            array_map(static fn (Message $m): string => KanvasMessageStore::bareId($m->getId()), $messages),
            self::survivingIds($trimmed),
        ));

        if ($dropped !== [] && $this->store instanceof KanvasMessageStore) {
            $this->store->archiveMessages($this->threadId, $dropped);
        } elseif ($dropped !== []) {
            $this->store->archive($this->threadId, count($dropped));
        }

        $this->messages = $trimmed;

        return $this;
    }

    /**
     * @param list<Message> $messages
     */
    public function tokensOf(array $messages): int
    {
        // The fold mutates what it merges; a measurement must leave the caller's messages untouched.
        $this->trimmer->trim(array_map(static fn (Message $message): Message => clone $message, $messages), PHP_INT_MAX);

        return $this->trimmer->getTotalTokens();
    }

    /**
     * @param list<Message> $messages
     * @return list<string>
     */
    private static function survivingIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            $ids[] = KanvasMessageStore::bareId($message->getId());

            foreach ((array) ($message->getMetadata(KanvasHistoryTrimmer::FOLDED_IDS) ?? []) as $folded) {
                $ids[] = KanvasMessageStore::bareId((string) $folded);
            }
        }

        return $ids;
    }
}
