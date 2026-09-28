<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;

/**
 * An inbound message rarely arrives alone: someone posts an article and its photos, texts a
 * sentence and then the correction, drops an album of seven. The agent has to see one unit, so the
 * parts of a flurry chain onto a head via `parent_id` and one turn answers all of them.
 *
 * The head lives in a **cache registry keyed on the policy's correlation keys**, not in the message
 * rows. Deliveries are parallel jobs and every message is INSERTED before it chains, so "the newest
 * row on the channel" is not stable: by the time a worker resolves its head, messages that arrived
 * after it are already visible. Deriving the head from row order produced captions orphaned from
 * their own photos, and three images chained under the last of them instead of the first.
 *
 * Whichever part reaches the registry first becomes the head and the rest adopt it, whatever order
 * the workers run in. Windows are compared against the messages' own timestamps rather than left to
 * cache TTL, so a frozen clock in tests behaves like a real one.
 */
final readonly class MessageBurstService
{
    /**
     * Marks a message as a continuation of the turn its parent opened, rather than a reply to it.
     * `parent_id` alone cannot say which: it carries social comment threading too, and consumers
     * that read it — `total_children`, the Typesense `children` summary,
     * `MessageOwnerChildNotificationActivity` — otherwise read a three-text SMS as a two-reply
     * thread and notify someone that their own message was answered.
     */
    public const string BURST_PART_TAG = 'burst-part';

    public function __construct(
        private Channel $channel,
        private BurstPolicy $policy,
    ) {
    }

    /**
     * Returns the head this message joins, or null when it IS the head (nothing to chain onto).
     */
    public function resolveHead(Message $message): ?Message
    {
        $keys = $this->burstKeys();

        if ($keys === []) {
            return null;
        }

        $at = $message->created_at->getTimestamp();
        $state = $this->openState($keys, $at);

        if ($state !== null) {
            $head = Message::find($state['id']);

            if ($head !== null && $head->getId() !== $message->getId()) {
                $this->remember(
                    $keys,
                    (int) $state['id'],
                    (int) $state['started'],
                    $at
                );

                return $head;
            }
        }

        $this->remember(
            $keys,
            $message->getId(),
            $at,
            $at
        );

        return null;
    }

    /**
     * Namespaced per channel so two conversations never share a head, and per key so a policy can
     * offer several names for the same burst.
     *
     * @return list<string>
     */
    private function burstKeys(): array
    {
        $prefix = 'message-burst-head:' . $this->channel->getId() . ':';

        return array_values(array_map(
            fn (string $key): string => $prefix . $key,
            array_filter($this->policy->correlationKeys, fn (string $key): bool => trim($key) !== '')
        ));
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
            if (abs($at - (int) $state['last']) <= $this->policy->chainIdleSeconds
                && abs($at - (int) $state['started']) <= $this->policy->maxSeconds) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     */
    private function remember(
        array $keys,
        int $headId,
        int $started,
        int $last
    ): void {
        foreach ($keys as $key) {
            Cache::put(
                $key,
                ['id' => $headId, 'started' => $started, 'last' => $last],
                $this->policy->maxSeconds + $this->policy->chainIdleSeconds
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
     * The whole burst as one turn.
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
