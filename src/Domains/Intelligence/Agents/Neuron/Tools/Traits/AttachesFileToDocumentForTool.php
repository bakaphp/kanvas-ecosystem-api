<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Approvals\Enums\ApprovalAttachmentFieldEnum;
use Kanvas\Scribe\Models\BaseModel;

/**
 * Shared file resolution for the attach_* tools: a file a person handed the agent this turn
 * (filesystem_id, the shape every Kanvas file tool speaks) or a plain URL, attached to the
 * document under one named slot.
 */
trait AttachesFileToDocumentForTool
{
    /**
     * @return array{url: string, name: string, field_name: string}|array{reason: string, message: string}
     */
    private function attachFileToDocument(
        BaseModel $document,
        ?int $filesystemId = null,
        ?string $fileUrl = null,
        ?string $fileName = null,
        ?string $fieldName = null,
    ): array {
        $filesystem = null;

        if ($filesystemId !== null) {
            // Company-scoped, not just app: the id comes from the model, so an app hosting several
            // companies would otherwise attach another company's document off a hallucinated id.
            $filesystem = Filesystem::query()
                ->where('id', $filesystemId)
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->first();

            if ($filesystem === null) {
                return [
                    'reason' => 'file_not_found',
                    'message' => "No file with filesystem_id {$filesystemId} for this company.",
                ];
            }
        }

        $url = Str::trimToNull($filesystem?->url ?? $fileUrl);

        if ($url === null) {
            return [
                'reason' => 'no_file_given',
                'message' => 'Pass either filesystem_id (a file already in Kanvas, e.g. from an attachment '
                    . 'marker or download_attachment) or file_url.',
            ];
        }

        $name = Str::trimToNull($fileName)
            ?? Str::trimToNull($filesystem?->name)
            ?? basename(parse_url($url, PHP_URL_PATH) ?: 'file');

        // One slot per kind of document. Re-attaching the source invoice PDF updates that single
        // row, but a W-9 or a remittance has to land beside it — sharing the slot would silently
        // replace the invoice the approval flow reads back through ReadsApprovalSourceFields.
        $slot = Str::trimToNull($fieldName) ?? ApprovalAttachmentFieldEnum::INVOICE_PDF->value;

        if ($filesystem !== null) {
            $document->addFile($filesystem, $slot);
        } else {
            $document->addFileFromUrl($url, $slot);
        }

        return ['url' => $url, 'name' => $name, 'field_name' => $slot];
    }
}
