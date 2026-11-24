<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Actions\Concerns;

use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;

trait EditsOneDriveDocument
{
    /**
     * @return array{item: array<string, mixed>, content: string}
     */
    private function downloadForEdit(OneDriveClient $client, string $pathOrId, OneDriveDocumentTypeEnum $type): array
    {
        $item = $client->getItem($pathOrId);

        if (OneDriveDocumentTypeEnum::fromFileName($item['name']) !== $type) {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::UNSUPPORTED_TYPE,
                'Only .' . $type->extension() . ' files can be edited with this tool.'
            );
        }

        return $client->download($item);
    }

    /**
     * Uploads with the eTag of the copy that was edited, so a change made meanwhile is never overwritten.
     */
    private function replaceEdited(OneDriveClient $client, array $item, string $bytes): array
    {
        $etag = (string) ($item['etag'] ?? '');

        if ($etag === '') {
            throw new OneDriveException(
                OneDriveErrorReasonEnum::REQUEST_FAILED,
                'OneDrive did not return a version tag for this file; refusing to overwrite it'
            );
        }

        return $client->replace($item['id'], $bytes, $etag);
    }
}
