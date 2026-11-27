<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * A message the agent writes in a Slack conversation without being spoken to first. It is sent from
 * another conversation's turn, so it is in no history of this one, and a transcript that opens with
 * the agent speaking loses that opening when it is loaded. The turn that answers it is handed it as
 * prompt text instead, or "him" in the reply points at nothing.
 */
class SlackUnpromptedMessageService
{
    private const string FLAG = 'sent_unprompted';
    private const int MAX_MESSAGES = 5;
    private const int MAX_CHARS = 2000;

    /**
     * Files it on the channel the reply will arrive on: a one-to-one DM hangs off the teammate, a
     * group DM is the agent's own room. Null when the agent has no Slack team to key the channel on.
     */
    public function record(
        Agent $agent,
        string $slackChannelId,
        string $message,
        ?Users $oneToOneWith = null,
    ): ?Channel {
        $author = $agent->user;
        $teamId = $author !== null ? new SlackReceiverService()->teamIdFor($agent) : null;

        if ($author === null || $teamId === null) {
            return null;
        }

        $channel = new SlackConversationChannelService()->channelFor(
            $agent,
            $oneToOneWith ?? $author,
            $teamId,
            $slackChannelId,
        );

        new PostChannelMessageAction(
            channel: $channel,
            author: $author,
            verb: 'slack',
            content: $message,
            extraPayload: [
                'from_ia' => true,
                'agent_id' => $agent->getId(),
                'slack_channel' => $slackChannelId,
                self::FLAG => true,
            ],
        )->execute();

        return $channel;
    }

    /**
     * Only the run of them right before the inbound message: once answered, they live on in the
     * answering turn, which is stored with this text in it.
     */
    public function render(Channel $channel, Message $inbound): ?string
    {
        $earlier = $channel->messages()
            ->where('messages.id', '!=', $inbound->getId())
            ->where('messages.created_at', '<=', $inbound->created_at)
            ->where('messages.is_deleted', 0)
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit(self::MAX_MESSAGES)
            ->get();

        $sent = [];

        foreach ($earlier as $message) {
            if (! (bool) ($message->message[self::FLAG] ?? false)) {
                break;
            }

            $sent[] = '- ' . Str::limit(trim($message->contentText()), self::MAX_CHARS);
        }

        if ($sent === []) {
            return null;
        }

        return 'You wrote this to them here earlier, without them writing first, and it is not in your '
            . 'history of this conversation. Their message below is most likely the answer to it, so read '
            . 'it against what you said, not against anything else.' . "\n"
            . implode("\n", array_reverse($sent));
    }
}
