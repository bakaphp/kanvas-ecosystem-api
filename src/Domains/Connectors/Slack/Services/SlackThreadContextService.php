<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Illuminate\Support\Str;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * The thread a tagged message sits in, rendered for the agent's turn. The agent's own history is one
 * session per Slack thread and holds only the turns it took part in; the replies people wrote
 * without tagging it live on the channel row alone. Handing the thread over with the mention is what
 * lets "@agent" with nothing else mean "look at this thread and act".
 */
class SlackThreadContextService
{
    public const int MAX_MESSAGES = 50;
    private const int MAX_CHARS = 600;

    public function __construct(
        private readonly SlackMessageLookupService $lookup = new SlackMessageLookupService(),
    ) {
    }

    public function render(Channel $channel, Message $inbound): ?string
    {
        $root = $this->root($channel, $inbound);

        if ($root === null) {
            return null;
        }

        // Newest first and reversed, so a long thread loses its oldest replies rather than the
        // ones the mention is about.
        $messages = Message::query()
            ->where(fn ($query) => $query->whereKey($root->getId())->orWhere('parent_id', $root->getId()))
            ->whereKeyNot($inbound->getId())
            ->where('is_deleted', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return null;
        }

        $lines = $messages
            ->map(fn (Message $message): string => '- ' . $this->line($message))
            ->all();

        return 'Slack thread so far, oldest first. Replies that did not mention you are included for '
            . 'context; only the latest message below is addressed to you.' . "\n"
            . implode("\n", $lines);
    }

    private function root(Channel $channel, Message $inbound): ?Message
    {
        if ((int) $inbound->parent_id > 0) {
            return Message::query()->whereKey((int) $inbound->parent_id)->where('is_deleted', 0)->first();
        }

        return $this->lookup->find($channel, (string) ($inbound->message['slack_thread_ts'] ?? ''));
    }

    /**
     * Room rows already carry "Name: text" from ingest; the agent's own replies do not, so they are
     * labelled here.
     */
    private function line(Message $message): string
    {
        $text = Str::limit(trim($message->contentText()), self::MAX_CHARS);

        if ((bool) ($message->message['from_ia'] ?? false)) {
            return 'You: ' . $text;
        }

        return $text;
    }
}
