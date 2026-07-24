<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

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

        // The thread as [self, parent, …, root] via AsTree's materialized path (one query): the mention
        // resolves to a project via this message OR any ancestor, and the PM's reply threads under the
        // root (last element) so it stays flat.
        $ancestors = $message->joinAncestors();
        $threadRootId = (int) $ancestors->last()->getId();

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
            $project = $this->projectForPmMention($ancestors, $agent);
            if ($project !== null) {
                WakeAgentForProjectJob::dispatch(
                    $project,
                    WakeAgentForProjectJob::REASON_MENTION,
                    $this->mentionTriggerWithAuthor($message),
                    $threadRootId,
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
     * The project whose channel this message — or any thread ancestor — is on AND whose PM is the
     * mentioned agent; null otherwise. Ancestors count because a threaded reply carries only parent_id
     * and often isn't re-tagged onto the project channel.
     *
     * @param iterable<Message> $ancestors the mention message followed by its parent chain up to the root
     */
    private function projectForPmMention(iterable $ancestors, Agent $agent): ?Project
    {
        foreach ($ancestors as $node) {
            $project = $this->projectOnMessageChannels($node, $agent);

            if ($project !== null) {
                return $project;
            }
        }

        return null;
    }

    private function projectOnMessageChannels(Message $message, Agent $agent): ?Project
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

    /**
     * The mention text prefixed with WHO sent it, so the PM can resolve "me"/"I"/"my" to the actual
     * requester and reply to them — otherwise it only sees the bare text and guesses the wrong person.
     */
    private function mentionTriggerWithAuthor(Message $message): string
    {
        $text = $this->mentionText($message);
        $author = $message->user;
        if ($author === null) {
            return $text;
        }

        $name = trim((string) ($author->firstname ?? '') . ' ' . (string) ($author->lastname ?? ''));
        if ($name === '') {
            $name = (string) ($author->displayname ?? 'a member');
        }

        try {
            $displayname = trim($author->getAppProfile($message->app)->displayname);
            $handle = $displayname !== '' ? '@' . $displayname : null;
        } catch (Throwable) {
            $handle = null;
        }

        $label = $handle !== null ? " ({$handle})" : '';

        // Authoritative agent-vs-human check: the sender's user IS an agent iff an Agent record points
        // at it. Tell the PM which id to assign to so a name shared by a human and an agent can't get
        // it wrong (e.g. human "kaioken" users_id=667 vs agent "Kaioken" agent_id=2631).
        $senderAgent = Agent::fromUser($author->getId(), $message->app, $message->company);

        if ($senderAgent !== null) {
            return sprintf(
                'This message was sent by the AGENT %s%s (agent_id=%d). If they say "me"/"I"/"my", they '
                . 'mean THIS agent — to assign it a plan use assign_nervous_system_plan with agent_id=%d.'
                . "\n\n%s",
                $name,
                $label,
                $senderAgent->getId(),
                $senderAgent->getId(),
                $text,
            );
        }

        return sprintf(
            'This message was sent by the HUMAN member %s%s, users_id=%d. If they say "me"/"I"/"my", they '
            . 'mean THIS person — act on their request and reply to them. To assign a plan to them use '
            . 'assign_nervous_system_plan with users_id=%d (they are a HUMAN, NOT an agent — never assign '
            . "to an agent that merely shares their name).\n\n%s",
            $name,
            $label,
            $author->getId(),
            $author->getId(),
            $text,
        );
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
