<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\AIAssistAgentResponderAction;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;

/**
 * The agent's answer to a teammate on a lead's AI Assist channel. The agent is the one the
 * channel's session was opened with; a channel with no session or no agent is not answered.
 */
final class RespondOnAiAssistChannelListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';
    public int $tries = 3;

    public function shouldQueue(ChannelMessageAttachedEvent $event): bool
    {
        // The agent's own answer lands on the same channel; only a person's message gets one.
        return $event->channel->isAiAssistChannel() && $event->message->isFromHuman();
    }

    public function handle(ChannelMessageAttachedEvent $event): void
    {
        $this->overwriteAppService($event->channel->app);

        $channel = $event->channel;

        if ($channel->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value) !== 'AI_ASSIST') {
            return;
        }

        $session = Session::query()
            ->fromApp($channel->app)
            ->where('channel_id', $channel->getId())
            ->notDeleted()
            ->first();

        if ($session?->agent === null) {
            return;
        }

        try {
            new AIAssistAgentResponderAction(
                $channel,
                $event->message,
                $session->agent,
                $session
            )->execute();
        } catch (ValidationException|AgentReplySkippedException) {
            // Nothing to answer (no lead on the message, an empty reply): not a fault.
        }
    }
}
