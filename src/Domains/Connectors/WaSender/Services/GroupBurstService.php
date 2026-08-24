<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * A group message rarely arrives alone: someone posts an article and its photos, or drops an album
 * of seven. The agent has to see one unit, so the parts of a flurry chain onto a head via
 * `parent_id`.
 *
 * The head lives in a **cache registry keyed on album-id-or-speaker**, not in the message rows.
 * Deliveries are parallel jobs and every message is INSERTED before it chains, so "the newest row
 * on the channel" is not stable: by the time a worker resolves its head, messages that arrived
 * after it are already visible. Deriving the head from row order produced captions orphaned from
 * their own photos, and three images chained under the last of them instead of the first.
 *
 * Whichever part reaches the registry first becomes the head and the rest adopt it, whatever order
 * the workers run in. Windows are compared against the messages' own timestamps rather than left
 * to cache TTL, so a frozen clock in tests behaves like a real one.
 */
final readonly class GroupBurstService
{
    public function __construct(
        private Channel $channel,
        private int $idleSeconds,
        private int $maxSeconds,
    ) {
    }

    public function resolveHead(Message $message, InboundMessage $inbound): ?Message
    {
        $keys = $this->burstKeys($inbound);

        if ($keys === []) {
            return null;
        }

        $at = $message->created_at->getTimestamp();
        $state = $this->openState($keys, $at);

        if ($state !== null) {
            $head = Message::find($state['id']);

            if ($head !== null && $head->getId() !== $message->getId()) {
                $this->remember($keys, (int) $state['id'], (int) $state['started'], $at);

                return $head;
            }
        }

        $this->remember($keys, $message->getId(), $at, $at);

        return null;
    }

    /**
     * Every name this burst answers to, most specific first. The speaker key is what binds an album
     * to the caption it illustrates — they share a sender but not an album id — while the album key
     * lets a straggling part rejoin its siblings after the idle window would have closed them.
     *
     * Deliberately NOT "the newest row on the channel": deliveries are parallel jobs and each
     * message is INSERTED before it chains, so messages that arrived later are already visible when
     * a worker resolves its head.
     *
     * @return list<string>
     */
    private function burstKeys(InboundMessage $inbound): array
    {
        $prefix = 'wasender:burst-head:' . $this->channel->getId() . ':';

        return array_values(array_filter([
            $inbound->albumId !== null ? $prefix . 'album:' . $inbound->albumId : null,
            $inbound->senderIdentity() !== null ? $prefix . 'speaker:' . $inbound->senderIdentity() : null,
        ]));
    }

    /**
     * @param list<string> $keys
     *
     * @return array{id: int, started: int, last: int}|null
     */
    private function openState(array $keys, int $at): ?array
    {
        foreach ($keys as $key) {
            $state = Cache::get($key);

            if (! is_array($state)) {
                continue;
            }

            // Compared against the messages' own timestamps rather than left to cache TTL, so a
            // frozen clock in tests behaves like a real one.
            if (abs($at - (int) $state['last']) <= $this->idleSeconds
                && abs($at - (int) $state['started']) <= $this->maxSeconds) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function remember(array $keys, int $headId, int $started, int $last): void
    {
        foreach ($keys as $key) {
            Cache::put(
                $key,
                ['id' => $headId, 'started' => $started, 'last' => $last],
                $this->maxSeconds + $this->idleSeconds
            );
        }
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
}
