<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Intelligence\Notifications\ReceptionistMessageNotification;
use Kanvas\Users\Models\Users;

/**
 * Shared "take a message" logic for lead and deal tools: message-body formatting, owner
 * notification and the response shape. Each tool only differs in how it resolves its entity
 * and which note action it records with — everything else lives here so the two stay in sync.
 */
trait TakesMessageForEntity
{
    /**
     * @return array{status: string, message: string}
     */
    protected function emptyMessageError(): array
    {
        return [
            'status' => 'error',
            'message' => 'The message is empty — capture what the person actually wants passed along before calling this tool.',
        ];
    }

    /**
     * The agent's own acting user (via HasKanvasContext), so the note isn't attributed to the shared
     * company AI user. Null without context, letting the record action's own default apply.
     */
    protected function actingNoteUser(): ?Users
    {
        return isset($this->user) ? $this->user : null;
    }

    protected function normalizeOptional(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    protected function receptionistMessageBody(string $message, ?string $forWhom, ?string $callback): string
    {
        return 'Receptionist message'
            . ($forWhom !== null ? ' for ' . $forWhom : '')
            . ': ' . $message
            . ($callback !== null ? ' (callback: ' . $callback . ')' : '');
    }

    /**
     * Notify the entity's owner (if any) and build the shared success response.
     *
     * @return array<string, mixed>
     */
    protected function finalizeTakenMessage(
        BaseModel $entity,
        string $idKey,
        string $message,
        ?string $forWhom,
        ?string $callback,
        bool $recorded,
    ): array {
        $owner = $entity->owner;
        $notified = false;

        if ($owner !== null) {
            $owner->notify(new ReceptionistMessageNotification(
                $entity,
                [
                    'app' => $entity->app,
                    'company' => $entity->company,
                    'message' => $message,
                    'for_whom' => $forWhom,
                    'callback_number' => $callback,
                    $idKey => $entity->getId(),
                ],
            ));
            $notified = true;
        }

        return [
            'status' => 'success',
            $idKey => $entity->getId(),
            'recorded' => $recorded,
            'owner_notified' => $notified,
            'note' => $notified
                ? 'Message saved as a note and the assigned owner was notified.'
                : 'Message saved as a note. No owner is assigned, so no one was pinged — consider a handoff if it is urgent.',
        ];
    }
}
