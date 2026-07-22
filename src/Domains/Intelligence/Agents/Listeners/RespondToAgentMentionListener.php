<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;

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
        $payload = $message->message;

        // Agent-authored (from_ia) messages are parsed so an agent can @mention a human to notify
        // them (NotifyMentionedUsersListener), but they must never wake another agent — delegation
        // is via assign_task, not by one agent tagging another. This is the anti-loop guard.
        if (is_array($payload) && ($payload['from_ia'] ?? false)) {
            return;
        }

        // Project-ingest messages (transcript/email/mention) already wake the PM via
        // IngestToProjectAction — don't let a mention inside their content wake it a second time.
        if (is_array($payload) && isset($payload['ingest_type'])) {
            return;
        }

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

            // If the mention is on a project channel whose PM is this agent, drive it through the
            // project's execution loop (full context + board tools + ledger) instead of a plain
            // mention reply — and skip the generic responder so the PM never double-answers.
            $project = $this->projectForPmMention($message, $agent);
            if ($project !== null) {
                WakeAgentForProjectJob::dispatch(
                    $project,
                    WakeAgentForProjectJob::REASON_MENTION,
                    $this->mentionText($message),
                );

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

    /**
     * The project whose default/any channel this message is on AND whose PM is the mentioned agent —
     * null when the mention isn't a project-PM mention.
     */
    private function projectForPmMention(Message $message, Agent $agent): ?Project
    {
        $entityIds = $message->channels()
            ->where('entity_namespace', Project::class)
            ->pluck('entity_id')
            ->all();

        foreach ($entityIds as $entityId) {
            $project = Project::query()->where('id', (int) $entityId)->notDeleted()->first();

            if ($project !== null && (int) $project->agent_id === (int) $agent->getId()) {
                return $project;
            }
        }

        return null;
    }

    private function mentionText(Message $message): string
    {
        $payload = $message->message;
        if (! is_array($payload)) {
            return is_scalar($payload) ? (string) $payload : '';
        }

        foreach (['content', 'text', 'message', 'body'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return '';
    }
}
