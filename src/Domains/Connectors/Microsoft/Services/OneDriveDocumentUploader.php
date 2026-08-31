<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Services;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Microsoft\Graph\Generated\Drives\Item\Items\Item\CreateLink\CreateLinkPostRequestBody;
use Microsoft\Graph\Generated\Models\DriveItem;
use Microsoft\Graph\GraphServiceClient;
use RuntimeException;

/**
 * Reusable OneDrive content uploader. It deliberately knows nothing about
 * Neuron, generated documents, or Kanvas file models.
 */
class OneDriveDocumentUploader
{
    public function __construct(
        private readonly GraphServiceClient $client,
        private readonly string $userPrincipalName,
    ) {
        if (blank($this->userPrincipalName)) {
            throw new InvalidArgumentException('A Microsoft 365 user principal name is required.');
        }
    }

    public function upload(string $localPath, ?string $existingItemId, ?string $targetFolder): DriveItem
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new RuntimeException("The document at '{$localPath}' does not exist or cannot be read.");
        }

        if (blank($existingItemId) && blank($targetFolder)) {
            throw new InvalidArgumentException('Provide existing_item_id to update or target_folder to upload a new document.');
        }

        $content = Utils::streamFor((string) file_get_contents($localPath));

        if (filled($existingItemId)) {
            return $this->client->users()
                ->byUserId($this->userPrincipalName)
                ->drive()
                ->items()
                ->byDriveItemId($existingItemId)
                ->content()
                ->put($content)
                ->wait();
        }

        $folder = trim((string) $targetFolder, '/');
        $remotePath = "root:/{$folder}/" . basename($localPath) . ':';

        return $this->client->users()
            ->byUserId($this->userPrincipalName)
            ->drive()
            ->items()
            ->byDriveItemId($remotePath)
            ->content()
            ->put($content)
            ->wait();
    }

    public function createShareLink(string $itemId, string $type = 'view', string $scope = 'organization'): string
    {
        $body = new CreateLinkPostRequestBody();
        $body->setType($type);
        $body->setScope($scope);

        $permission = $this->client->users()
            ->byUserId($this->userPrincipalName)
            ->drive()
            ->items()
            ->byDriveItemId($itemId)
            ->createLink()
            ->post($body)
            ->wait();

        return $permission->getLink()?->getWebUrl()
            ?? throw new RuntimeException('Microsoft Graph did not return a share URL.');
    }
}
