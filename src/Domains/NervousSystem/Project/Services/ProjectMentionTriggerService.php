<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Services;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * Builds the trigger text handed to the PM when an agent is @mentioned on a project or one of its
 * plans. It prefixes the mention with WHO sent it (and whether that sender is a human or an agent, with
 * the id to assign to) so the PM can resolve "me"/"I"/"my" to the real requester instead of guessing —
 * a name shared by a human and an agent (human "kaioken" users_id=667 vs agent "Kaioken" agent_id=2631)
 * would otherwise route the work to the wrong one. An optional focus preamble pins the reply to a
 * specific plan.
 */
class ProjectMentionTriggerService
{
    public function buildTrigger(Message $message, ?string $focusPreamble = null): string
    {
        $text = $message->contentText();

        if ($focusPreamble !== null && $focusPreamble !== '') {
            $text = $focusPreamble . "\n\n" . $text;
        }

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
}
