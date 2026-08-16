<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Puts the agent's bot inside every public channel of the workspace.
 *
 * This is the part that surprises people: subscribing to `message.channels` gets you nothing on its
 * own. Slack scopes a bot token's channel history to channels the bot has JOINED — there is no
 * workspace-wide firehose for a bot app at any scope level. (The only real firehose is the Discovery
 * API, which is Enterprise Grid + org approval, a different product.) So "read all channels"
 * literally means "be in all channels".
 *
 * Private channels are out of reach by design — `conversations.join` cannot enter one, and no scope
 * changes that. A human inside each private channel has to `/invite` the bot; once they do, the
 * already-subscribed `message.groups` event starts flowing with no further work here.
 *
 * Joining is visible: Slack posts "<bot> has joined the channel" in each one.
 */
class JoinAllSlackChannelsAction
{
    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * @return array{joined: list<string>, already_member: list<string>, failed: list<string>}
     */
    public function execute(): array
    {
        $client = Client::getInstanceByAgent($this->agent);

        $joined = [];
        $alreadyMember = [];
        $failed = [];

        foreach ($client->listPublicConversations() as $conversation) {
            if ($conversation['is_member']) {
                $alreadyMember[] = $conversation['id'];

                continue;
            }

            try {
                $client->joinConversation($conversation['id']);
                $joined[] = $conversation['id'];
            } catch (Throwable $e) {
                // One channel the bot can't enter (admin-restricted, archived between the list and
                // the join) must not abort the sweep over the rest of the workspace.
                report($e);
                $failed[] = $conversation['id'];
            }
        }

        return [
            'joined' => $joined,
            'already_member' => $alreadyMember,
            'failed' => $failed,
        ];
    }
}
