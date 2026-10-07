<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Kanvas\Intelligence\Agents\Neuron\Stores\KanvasMessageStore;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use Override;

use const PHP_INT_MAX;

/**
 * Two differences from the vendor history, both load-bearing.
 *
 * It trims on load, not only on add. Since Neuron 4.1 the chat node sends `getMessages()` plus the
 * inbound turn to the provider and adds to the history afterwards, so a trim that only runs in
 * addMessage() runs after the first request of every turn: a rollup of 1,962 vendor emails went out
 * whole at 845K tokens (KANVAS-ECOSYSTEM-6HP), and a heartbeat thread passed 1M (KANVAS-ECOSYSTEM-6JD).
 *
 * It archives by identity instead of by count: KanvasHistoryTrimmer folds same-role turns, which
 * shortens the list without dropping anything, so the stock count would stamp the wrong rows.
 * Re-check both against the vendor on a Neuron bump.
 */
class KanvasChatHistory extends ChatHistory
{
    /**
     * @return list<Message>
     */
    #[Override]
    public function getMessages(): array
    {
        if ($this->messages === null) {
            $loaded = $this->store->loadActive($this->threadId);
            $this->messages = $this->trimmer->trim($loaded, $this->contextWindow);
            $this->archiveDropped($loaded, $this->messages);
        }

        return $this->messages;
    }

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
        $this->archiveDropped($messages, $trimmed);
        $this->messages = $trimmed;

        return $this;
    }

    /**
     * Compared as row ids: the store strips Neuron's prefix when it persists, and the fold recorded
     * the prefixed one, so a folded-in message would otherwise read as dropped and lose its row.
     *
     * @param list<Message> $before
     * @param list<Message> $kept
     */
    private function archiveDropped(array $before, array $kept): void
    {
        $dropped = array_values(array_diff(
            array_map(static fn (Message $m): string => KanvasMessageStore::bareId($m->getId()), $before),
            self::survivingIds($kept),
        ));

        if ($dropped === []) {
            return;
        }

        if ($this->store instanceof KanvasMessageStore) {
            $this->store->archiveMessages($this->threadId, $dropped);
        } else {
            $this->store->archive($this->threadId, count($dropped));
        }
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
