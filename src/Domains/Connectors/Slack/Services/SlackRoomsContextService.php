<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;

/**
 * The rooms the agent can read, rendered for its turn. Asked in a DM about "#agents-logs", an agent
 * whose history is the DM alone has no way to know it is even a member of that room, let alone which
 * channel_id to read — so it answers "invite me" to a room it was invited to weeks ago.
 *
 * Rooms and group DMs hang off the agent's own user (SlackConversationChannelService), which is what
 * separates them from DMs (owned by the human) and from the workspace listener's copy of the same room.
 */
class SlackRoomsContextService
{
    public const int MAX_ROOMS = 40;

    public function render(Agent $agent, Channel $current): ?string
    {
        $owner = $agent->user;
        $teamId = $this->teamIdFromSlug((string) $current->slug);

        if (! $owner instanceof Users || $teamId === null) {
            return null;
        }

        $rooms = Channel::query()
            ->fromApp($agent->app)
            ->fromCompany($agent->company)
            ->notDeleted()
            ->where('entity_namespace', Users::class)
            ->where('entity_id', $owner->getId())
            ->where('slug', 'like', 'slack-' . $teamId . '-%')
            ->whereKeyNot($current->getId())
            ->orderByDesc('last_message_id')
            ->limit(self::MAX_ROOMS)
            ->get();

        if ($rooms->isEmpty()) {
            return null;
        }

        $lines = $rooms
            ->map(fn (Channel $room): string => '- ' . $room->name . ' (channel_id ' . $room->getId()
                . ', Slack ' . $this->slackIdFromSlug((string) $room->slug, $teamId) . ')')
            ->all();

        return 'Slack rooms you are a member of and can read with read_channel_window, '
            . 'search_channel_messages and read_thread (by channel_id or name). Nothing from them is in '
            . 'this conversation until you read it:' . "\n" . implode("\n", $lines);
    }

    /**
     * `slack-{team}-{channel}` — neither id carries a hyphen, so the first segment is the workspace.
     */
    private function teamIdFromSlug(string $slug): ?string
    {
        return preg_match('/^slack-([a-z0-9]+)-[a-z0-9]+$/', $slug, $match) === 1 ? $match[1] : null;
    }

    private function slackIdFromSlug(string $slug, string $teamId): string
    {
        return strtoupper(substr($slug, strlen('slack-' . $teamId . '-')));
    }
}
