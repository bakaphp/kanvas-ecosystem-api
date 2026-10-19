<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Actions;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;

/**
 * The get-docs form uploads each file on its own and only records the filesystem ids inside the
 * submitted message payload (`data.{typeId}.files[]`), so nothing is attached to the message until
 * this runs.
 */
class AttachGetDocsFilesToMessageAction
{
    public function __construct(
        protected Message $message,
    ) {
    }

    /**
     * @return Collection<int, Filesystem>
     */
    public function execute(): Collection
    {
        $fieldNames = $this->collectFieldNamesByFileId();

        if ($fieldNames === []) {
            return collect();
        }

        // @todo restore once the docs frontend change ships
        $files = Filesystem::query()
            ->fromApp($this->message->app)
            /* ->where(
                fn ($query): mixed => $query
                    ->whereIn('companies_id', [$this->message->companies_id, 0])
                    ->orWhere('users_id', $this->message->users_id)
            ) */
            ->whereIn('id', array_keys($fieldNames))
            ->notDeleted()
            ->get();

        foreach ($files as $file) {
            // Unique per file: without app-level duplicates, a shared field name replaces the
            // previous file, and one document type can carry several files.
            $this->message->addFile($file, $fieldNames[$file->getId()] . '_' . $file->getId());
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    protected function collectFieldNamesByFileId(): array
    {
        $fieldNames = [];

        foreach ($this->message->getMessage()['data'] ?? [] as $typeId => $document) {
            if (! is_array($document)) {
                continue;
            }

            $fieldName = Str::slug((string) ($document['type']['name'] ?? 'document_' . $typeId), '_');

            foreach ($document['files'] ?? [] as $file) {
                $fileId = (int) ($file['id'] ?? 0);

                if ($fileId > 0) {
                    $fieldNames[$fileId] = $fieldName;
                }
            }
        }

        return $fieldNames;
    }
}
