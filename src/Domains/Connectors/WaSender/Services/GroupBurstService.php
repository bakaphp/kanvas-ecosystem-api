<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * A group message rarely arrives alone: someone posts an article and its photo 22 seconds later,
 * or drops an album of seven. The agent has to see one unit, so consecutive messages from the same
 * speaker chain onto a burst head via `parent_id`.
 *
 * Two signals. The album id is tried first and the speaker window **backs it up** — not
 * "otherwise": the first part of an album has no sibling filed yet, so without the fallback a
 * caption and the photos illustrating it become two separate bursts.
 *
 * 1. `messageContextInfo.messageAssociation` binds album parts under a shared parent message key.
 *    Deterministic, ignores time — the captured album spans 22 seconds with an 11-second internal
 *    gap. WhatsApp sends it, the vendor documents it nowhere, so it is a hint, never a dependency.
 * 2. The same speaker continuing inside an idle window. A different speaker closes the previous
 *    burst, which is why the newest message on the channel decides.
 */
final readonly class GroupBurstService
{
    /**
     * How far back to look. A burst is recent and small by definition; anything older than this
     * many messages on a busy channel is a different conversation.
     */
    private const int LOOKBACK = 30;

    public function __construct(
        private Channel $channel,
        private int $idleSeconds,
        private int $maxSeconds,
    ) {
    }

    public function resolveHead(Message $message, InboundMessage $inbound): ?Message
    {
        $head = $this->headByAlbum($message, $inbound);

        $head ??= $this->headBySpeaker($this->recentMessages($message, 1)->first(), $inbound, $message);

        if ($head === null || $this->isOlderThanMaxWindow($head, $message)) {
            return null;
        }

        return $head;
    }

    /**
     * Album parts can arrive out of order and with arbitrary gaps, so any sibling already filed
     * under this album id anchors the burst regardless of when it landed.
     */
    private function headByAlbum(Message $message, InboundMessage $inbound): ?Message
    {
        if ($inbound->albumId === null) {
            return null;
        }

        foreach ($this->recentMessages($message, self::LOOKBACK) as $candidate) {
            if (($candidate->message['album_id'] ?? null) === $inbound->albumId) {
                return $candidate->parent ?? $candidate;
            }
        }

        return null;
    }

    /**
     * Only the newest message decides: if someone else spoke, or the gap is too long, the previous
     * burst is closed and this one opens a new head.
     */
    private function headBySpeaker(?Message $newest, InboundMessage $inbound, Message $message): ?Message
    {
        $speaker = $inbound->senderIdentity();

        if ($speaker === null || $newest === null) {
            return null;
        }

        if (($newest->message['sender_identity'] ?? null) !== $speaker) {
            return null;
        }

        if ($message->created_at->diffInSeconds($newest->created_at, absolute: true) > $this->idleSeconds) {
            return null;
        }

        return $newest->parent ?? $newest;
    }

    /**
     * @return Collection<int, Message>
     */
    public static function messagesFor(int $burstHeadId): Collection
    {
        return Message::query()
            ->where(function (Builder $query) use ($burstHeadId): void {
                $query->where('id', $burstHeadId)->orWhere('parent_id', $burstHeadId);
            })
            ->where('is_deleted', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The whole burst as one turn. Each part is already speaker-attributed, so a multi-party window
     * still reads as a conversation rather than an anonymous wall of text.
     *
     * @param Collection<int, Message> $messages
     */
    public static function promptFor(Collection $messages): string
    {
        return trim(
            $messages
                ->map(fn (Message $message): string => (string) ($message->message['content'] ?? ''))
                ->filter(fn (string $line): bool => trim($line) !== '')
                ->implode("\n\n")
        );
    }

    private function isOlderThanMaxWindow(Message $head, Message $message): bool
    {
        return $message->created_at->diffInSeconds($head->created_at, absolute: true) > $this->maxSeconds;
    }

    /**
     * @return Collection<int, Message>
     */
    private function recentMessages(Message $message, int $limit): Collection
    {
        return $this->channel->messages()
            ->where('messages.id', '!=', $message->getId())
            ->orderBy('messages.created_at', 'desc')
            ->orderBy('messages.id', 'desc')
            ->limit($limit)
            ->get();
    }
}
