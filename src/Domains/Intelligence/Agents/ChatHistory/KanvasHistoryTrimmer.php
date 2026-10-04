<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

/**
 * Turns the messages a store loaded, plus the turn being added, into the sequence a provider accepts:
 * consecutive same-role turns folded into one, leading non-user turns dropped, then the stock token
 * cut. The agent runs this on every addMessage(), so the live inbound turn folds into a trailing turn
 * the same way a loaded one does.
 *
 * The fold refuses to duplicate. A turn can reach the history twice — written once by the store's own
 * persist hook and once by the canonical writer for that surface (a connector's outbound,
 * PersistChatTurnToSocialAction) — and the two copies are identical. Concatenating them put
 * `"reply\n\nreply"` in the model's context, which the model imitated by emitting its own replies twice
 * (the duplicate-email feedback loop). Keep the longer copy when one contains the other; only genuinely
 * different turns concatenate.
 *
 * ChatHistory::addMessage() archives `count(before) − count(after)` messages in the store after a trim;
 * a fold shortens that list, so the number is wrong for a real archive. Every Kanvas store's archive()
 * is a no-op, which is what makes this safe — see KanvasMessageStore.
 */
final class KanvasHistoryTrimmer extends HistoryTrimmer
{
    public static function make(): self
    {
        return new self(new KanvasTokenCounter());
    }

    /**
     * @param Message[] $messages
     * @return Message[]
     */
    #[Override]
    public function trim(array $messages, int $contextWindow): array
    {
        return parent::trim(self::fold(array_values($messages)), $contextWindow);
    }

    /**
     * @param list<Message> $messages
     * @return list<Message>
     */
    public static function fold(array $messages): array
    {
        $folded = [];

        foreach ($messages as $message) {
            $last = end($folded) ?: null;

            if ($last !== null && self::foldable($last, $message) && $last->getRole() === $message->getRole()) {
                self::merge($last, $message);

                continue;
            }

            $folded[] = $message;
        }

        while ($folded !== [] && $folded[0]::class !== UserMessage::class) {
            array_shift($folded);
        }

        return array_values($folded);
    }

    private static function foldable(Message $a, Message $b): bool
    {
        foreach ([$a, $b] as $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
                return false;
            }
        }

        return true;
    }

    /**
     * setContents() resets the block list to a single TextContent, so the media blocks (image / PDF /
     * audio) of both turns are captured first and re-attached. Otherwise an attachment on the folded-in
     * turn — a PDF on an incoming @mention — is silently dropped and the model answers "I can't see the
     * file" even though the runner built the content block.
     */
    private static function merge(Message $into, Message $from): void
    {
        $media = [...self::mediaBlocks($into), ...self::mediaBlocks($from)];

        $into->setContents(self::coalesce((string) $into->getContent(), (string) $from->getContent()));

        foreach ($media as $block) {
            $into->addContent($block);
        }
    }

    /**
     * @return list<ContentBlockInterface>
     */
    private static function mediaBlocks(Message $message): array
    {
        return array_values(array_filter(
            $message->getContentBlocks(),
            static fn (ContentBlockInterface $block): bool => ! $block instanceof TextContent,
        ));
    }

    private static function coalesce(string $existing, string $incoming): string
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
