<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Messages\Models\Message;

/**
 * Shared "write a note" logic for the lead, person and organization note tools: the empty-note
 * refusal, the not-saved refusal and the success shape. Each tool differs only in how it resolves
 * its entity and which record action it writes with.
 *
 * These strings are prompt text the model acts on, so they live in one place — three copies drift,
 * and a model that is told "do not claim it was saved" by one tool but not another behaves
 * inconsistently for the same failure.
 */
trait WritesNoteForEntity
{
    /**
     * @return array{status: string, message: string}
     */
    protected function emptyNoteError(): array
    {
        return [
            'status' => 'error',
            'message' => 'The note is empty — write what you actually want recorded before calling this tool.',
        ];
    }

    /**
     * @param string $thread how to name the destination to the model, e.g. "the lead's activity thread"
     *
     * @return array<string, mixed>
     */
    protected function finalizeNote(
        ?Message $recorded,
        BaseModel $entity,
        string $note,
        string $idKey,
        string $thread,
    ): array {
        if ($recorded === null) {
            return [
                'status' => 'error',
                $idKey => $entity->getId(),
                'message' => 'The note could not be saved. Do not claim it was saved — tell the user it failed.',
            ];
        }

        return [
            'status' => 'success',
            $idKey => $entity->getId(),
            'note' => $note,
            'message' => 'Note saved on ' . $thread . '.',
        ];
    }
}
