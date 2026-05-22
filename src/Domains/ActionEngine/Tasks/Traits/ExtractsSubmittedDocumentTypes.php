<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Traits;

/**
 * Shared extraction of the document-type ids a get-docs message submitted.
 *
 * A get-docs message `data` payload is keyed by document-type id, each entry
 * carrying `type.id` — e.g. `{"9": {"type": {"id": 9, ...}, "files": [...]}}`.
 * Both the primary task-completion selector (ProcessMessageTaskUpdatesAction)
 * and the cross-checklist sibling fan-out (ChangeTaskEngagementItemStatusAction)
 * must scope to exactly these ids, so the logic lives here once.
 */
trait ExtractsSubmittedDocumentTypes
{
    /**
     * @param array<mixed> $data the `data` portion of a get-docs message payload
     *
     * @return array<int, int>
     */
    protected function extractGetDocsDocumentTypeIds(array $data): array
    {
        $ids = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $typeId = $entry['type']['id'] ?? null;
            if ($typeId !== null) {
                $ids[] = (int) $typeId;
            }
        }

        return array_values(array_unique($ids));
    }
}
