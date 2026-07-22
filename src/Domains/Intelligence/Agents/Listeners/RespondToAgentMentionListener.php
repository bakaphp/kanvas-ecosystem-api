<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;

/**
 * Social parsed the mentions; here we react to the ones that are agent-users. Each mentioned
 * user that resolves to an agent in the message's tenant gets its own reply job.
 */
class RespondToAgentMentionListener
{
    private const int ATTACHMENT_SETTLE_SECONDS = 6;

    public function handle(MessageMentionsStoredEvent $event): void
    {
        $message = $event->message;

        $awaitingUpload = ! $message->files()->exists();

        foreach ($event->mentionedUserIds as $userId) {
            $agent = Agent::fromUser(
                $userId,
                $message->app,
                $message->company
            );

            if ($agent === null) {
                continue;
            }

            $job = RespondToMentionJob::dispatch($agent, $message);

            if ($awaitingUpload) {
                $job->delay(
                    now()->addSeconds(self::ATTACHMENT_SETTLE_SECONDS)
                );
            }
        }
    }
}
