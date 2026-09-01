<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\Users;

/**
 * Properties have no dedicated image field on Location__c — photos/brochures live as regular
 * Salesforce Files (ContentDocumentLink), the same mechanism Chatter/Notes uses. This is the only
 * way to pull them: download each linked file's binary content and attach it to the Kanvas Product.
 */
class PullPropertyFilesAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Products $product,
        protected Users $user,
        protected SalesforceApiClient $client,
        protected string $salesforceId,
    ) {
    }

    public function execute(): void
    {
        // Location__c ids are always 15/18-char alphanumeric — anything else can't be a real one,
        // and this is the one place in the pipeline that interpolates it into a SOQL string.
        if (! ctype_alnum($this->salesforceId)) {
            return;
        }

        $result = $this->client->query(
            'SELECT ContentDocument.Title, ContentDocument.FileType, ContentDocument.LatestPublishedVersionId '
                . "FROM ContentDocumentLink WHERE LinkedEntityId = '{$this->salesforceId}'",
        );

        $filesystem = new FilesystemServices($this->app, $this->company);

        foreach ($result['records'] ?? [] as $record) {
            $this->attachFile($filesystem, $record['ContentDocument'] ?? []);
        }
    }

    private function attachFile(FilesystemServices $filesystem, array $document): void
    {
        $versionId = $document['LatestPublishedVersionId'] ?? null;
        $title = $document['Title'] ?? null;
        $fileType = $document['FileType'] ?? null;

        if ($versionId === null || $title === null || $fileType === null) {
            return;
        }

        $tempPath = sys_get_temp_dir() . '/' . uniqid('sf_property_file_', true) . '.' . strtolower((string) $fileType);
        file_put_contents($tempPath, $this->client->downloadContentVersion($versionId));

        $uploadedFile = new UploadedFile(
            $tempPath,
            $title . '.' . strtolower((string) $fileType),
            FilesystemServices::detectMimeType($tempPath),
            null,
            true,
        );

        $filesystemModel = $filesystem->upload($uploadedFile, $this->user);

        new AttachFilesystemAction($filesystemModel, $this->product)->execute($title);
    }
}
