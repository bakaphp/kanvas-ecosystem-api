<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\HasConversationHuman;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The human the agent is talking to — the session subject when it's a Users (an internal-teammate chat).
 *
 * On an in-app agent surface the tool's context user (`$this->user`) is the agent's OWN user, so
 * "remind me" / "list my reminders" must resolve to this human instead. The schedule_* tools share this
 * so create AND list/cancel all key off the same user — otherwise the agent creates for the human but
 * lists for itself and the two never line up. Null for a lead/people conversation or a session-less run;
 * callers fall back to their context user then.
 *
 * The person the host identified wins over the session's subject: a mention on a project or a channel
 * runs on a session that belongs to the record, not to whoever typed.
 */
trait ResolvesConversationHuman
{
    use HasConversationHuman;

    private function conversationHuman(?Session $session): ?Users
    {
        if ($this->conversationHuman !== null) {
            return $this->conversationHuman;
        }

        if ($session === null || $session->entity_namespace !== Users::class || ! $session->entity_id) {
            return null;
        }

        try {
            return Users::getById($session->entity_id);
        } catch (Throwable) {
            return null;
        }
    }
}
