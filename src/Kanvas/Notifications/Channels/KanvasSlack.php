<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Channels;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\Notification;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Deliver a notification as a Slack DM.
 *
 * The bot token lives on an AGENT (`AgentChannelTokenEnum::SLACK_BOT_TOKEN`), not on the app, so
 * `toSlack()` must name which agent is speaking. No connected agent means no delivery — silently,
 * because a plan that finished must not be un-finished by a missing integration.
 */
class KanvasSlack
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof UserInterface || ! method_exists($notification, 'toSlack')) {
            return;
        }

        $payload = $notification->toSlack($notifiable);
        $text = trim((string) ($payload['text'] ?? ''));
        $agent = $payload['agent'] ?? null;

        if ($text === '' || ! $agent instanceof Agent) {
            return;
        }

        try {
            $client = Client::getInstanceByAgent($agent);

            // Slack identifies people by its own id, and the only bridge we hold is the email. A
            // teammate whose Slack address differs from their Kanvas one is simply not reachable —
            // silently, which is worth knowing before this is relied on as the only channel.
            $slackUserId = $client->lookupUserIdByEmail((string) $notifiable->email);

            if ($slackUserId === null) {
                return;
            }

            $client->postMessage($client->openDirectMessageChannel($slackUserId), $text);
        } catch (Throwable $e) {
            // Best-effort, like every other channel here: the notification's other routes still land.
            report($e);
        }
    }
}
