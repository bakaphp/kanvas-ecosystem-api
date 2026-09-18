<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Turns stored rows into the in-memory history a provider will accept: consecutive same-role turns
 * merged, leading assistant turns dropped, and the result cut to the context window.
 *
 * The cut is the part that is easy to lose. AbstractChatHistory trims only inside addMessage(), and
 * InferenceNode::pendingConversation() sends `getMessages() + inbound` *before* that first add runs —
 * so a loader assigning $this->history directly ships the whole stored conversation, and the trim
 * that follows the call is discarded with the request. Past the provider's input ceiling the thread
 * then fails on every further turn with no way back (KANVAS-ECOSYSTEM-6F1). BoundToolResultsMiddleware
 * guards the other half of the same ceiling: what a tool loop adds inside a turn, which no trim reaches.
 */
trait RebuildsTrimmedHistory
{
    /**
     * @param list<Message> $messages oldest first
     */
    protected function applyLoadedHistory(array $messages): void
    {
        $coalesced = [];

        foreach ($messages as $message) {
            $last = end($coalesced) ?: null;

            if ($last !== null && $last->getRole() === $message->getRole()) {
                $last->setContents(self::coalesceContent(
                    (string) $last->getContent(),
                    (string) $message->getContent(),
                ));

                continue;
            }

            $coalesced[] = $message;
        }

        while ($coalesced !== [] && $coalesced[0]->getRole() !== MessageRole::USER->value) {
            array_shift($coalesced);
        }

        if ($coalesced === []) {
            return;
        }

        $this->history = array_values($coalesced);
        $this->trimHistory();
    }

    /**
     * Fold a live turn into the trailing turn when it shares its role, so the alternation providers
     * require survives a history that has two of the same in a row.
     *
     * The persist hook still runs for the folded message — it is a new turn of the conversation even
     * when the context shows it as one message.
     */
    protected function mergeOrAppend(Message $message): ChatHistoryInterface
    {
        $last = end($this->history) ?: null;

        if ($last === null || $last->getRole() !== $message->getRole()) {
            return parent::addMessage($message);
        }

        $last->setContents(self::coalesceContent(
            (string) $last->getContent(),
            (string) $message->getContent(),
        ));

        $this->trimHistory();
        $this->onNewMessage($message);
        $this->setMessages($this->history);

        return $this;
    }

    /**
     * Merge two same-role turns while refusing to duplicate. A turn can reach the history twice —
     * written once by the history's own persist hook and once by the canonical writer for that surface
     * (a connector's outbound, PersistChatTurnToSocialAction) — and the two copies are identical.
     * Concatenating them put `"reply\n\nreply"` in the model's context, which the model imitated by
     * emitting its own replies twice (the duplicate-email feedback loop). Keep the longer copy when one
     * contains the other; only genuinely different turns concatenate.
     */
    private static function coalesceContent(string $existing, string $incoming): string
    {
        $a = trim($existing);
        $b = trim($incoming);

        if ($b === '' || $a === $b || ($a !== '' && str_contains($a, $b))) {
            return $existing;
        }

        if ($a === '' || str_contains($b, $a)) {
            return $incoming;
        }

        return $existing . "\n\n" . $incoming;
    }
}
