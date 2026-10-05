<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Kanvas\Intelligence\Agents\Neuron\Stores\KanvasMessageStore;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use Override;

use const PHP_INT_MAX;

/**
 * Two departures from the vendor history; re-check both against it on a Neuron bump.
 *
 * It trims what the store loads. The stock history trims only inside addMessage(), and since Neuron 4
 * the turn's inbound message is added only after the provider call succeeds: InferenceNode's
 * pendingConversation() sends getMessages() plus the inbound as loaded. So the first inference of
 * every turn carried the whole stored thread, and the trim that followed went out with the request:
 * a Lead or channel that outgrew the model's input limit failed on every further turn (Gemini 400,
 * "input token count exceeds the maximum number of tokens allowed (1048576)", KANVAS-ECOSYSTEM-6F1).
 * The rollup and channel stores never archive, so for them the load is the only cut there is.
 *
 * It archives by identity instead of by count: KanvasHistoryTrimmer folds same-role turns, which
 * shortens the list without dropping anything, so the stock count would stamp the wrong rows.
 */
class KanvasChatHistory extends ChatHistory
{
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
     * @param list<Message> $messages
     */
    public function tokensOf(array $messages): int
    {
        // The fold mutates what it merges; a measurement must leave the caller's messages untouched.
        $this->trimmer->trim(array_map(static fn (Message $message): Message => clone $message, $messages), PHP_INT_MAX);

        return $this->trimmer->getTotalTokens();
    }

    /**
     * @param list<Message> $before
     * @param list<Message> $after
     */
    private function archiveDropped(array $before, array $after): void
    {
        // Compared as row ids: the store strips Neuron's prefix when it persists, and the fold recorded
        // the prefixed one, so a folded-in message would otherwise read as dropped and lose its row.
        $dropped = array_values(array_diff(
            array_map(static fn (Message $m): string => KanvasMessageStore::bareId($m->getId()), $before),
            self::survivingIds($after),
        ));

        if ($dropped === []) {
            return;
        }

        if ($this->store instanceof KanvasMessageStore) {
            $this->store->archiveMessages($this->threadId, $dropped);

            return;
        }

        $this->store->archive($this->threadId, count($dropped));
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
