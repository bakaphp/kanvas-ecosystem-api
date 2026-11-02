<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Shared "write a note" logic for the lead, person and organization note tools: the empty-note
 * refusal, the not-saved refusal, the files handed over with the note and the success shape. Each
 * tool differs only in how it resolves its entity and which record action it writes with.
 *
 * These strings are prompt text the model acts on, so they live in one place — three copies drift,
 * and a model that is told "do not claim it was saved" by one tool but not another behaves
 * inconsistently for the same failure.
 */
trait WritesNoteForEntity
{
    use AttachesFileToEntity;
    use NormalizesIdListParam;

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
     * A document a person sent along with their request (a meeting transcript, a spreadsheet) belongs
     * on the note that records it, not as a URL pasted into the text: a pasted link is not an
     * attachment the team can open from the activity thread.
     */
    protected function noteFilesProperty(): ArrayProperty
    {
        return new ArrayProperty(
            name: 'filesystem_ids',
            description: 'Optional files to attach to the note, by filesystem_id — the ids in the "Attached file on '
                . 'this message" markers of the message you are acting on, or ones an upload tool returned. Use '
                . 'this whenever the person handed you a document that belongs with the note, instead of '
                . 'pasting its URL into the text.',
            required: false,
            items: new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The id of a file already stored in Kanvas.',
            ),
        );
    }

    /**
     * @param string $thread how to name the destination to the model, e.g. "the lead's activity thread"
     * @param list<int> $filesystemIds files to hang on the saved note
     *
     * @return array<string, mixed>
     */
    protected function finalizeNote(
        ?Message $recorded,
        BaseModel $entity,
        string $note,
        string $idKey,
        string $thread,
        array $filesystemIds = [],
    ): array {
        if ($recorded === null) {
            return [
                'status' => 'error',
                $idKey => $entity->getId(),
                'message' => 'The note could not be saved. Do not claim it was saved — tell the user it failed.',
            ];
        }

        $response = [
            'status' => 'success',
            $idKey => $entity->getId(),
            'message_id' => (int) $recorded->getId(),
            'note' => $note,
            'message' => 'Note saved on ' . $thread . '.',
        ];

        if ($filesystemIds === []) {
            return $response;
        }

        ['attached' => $attached, 'unavailable' => $unavailable] = $this->attachFilesToNote($recorded, $filesystemIds);

        $response['files_attached'] = $attached;
        $response['files_unavailable'] = $unavailable;

        if ($unavailable !== []) {
            $response['message'] .= sprintf(
                ' %d file(s) could not be attached — say so, and never write a URL in their place.',
                count($unavailable),
            );
        }

        return $response;
    }

    /**
     * @param list<int> $filesystemIds
     *
     * @return array{attached: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}
     */
    private function attachFilesToNote(Message $note, array $filesystemIds): array
    {
        $attached = [];
        $unavailable = [];

        foreach ($filesystemIds as $filesystemId) {
            $result = $this->attachFileToEntity($note, 'note', filesystemId: $filesystemId);

            if ($result['status'] !== 'success') {
                $unavailable[] = [
                    'filesystem_id' => $filesystemId,
                    'reason' => $result['message'],
                ];

                continue;
            }

            $attached[] = [
                'filesystem_id' => $result['filesystem_id'],
                'file_name' => $result['file_name'],
            ];
        }

        return ['attached' => $attached, 'unavailable' => $unavailable];
    }
}
