<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice;

use InvalidArgumentException;
use Kanvas\Connectors\Microsoft\Services\OneDriveDocumentUploader;
use Kanvas\Connectors\Microsoft\Services\OneDriveGraphClientFactory;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Services\GeneratedDocumentStore;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Microsoft\Kiota\Abstractions\ApiException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use RuntimeException;

#[AgentTool(name: 'Upload Document to OneDrive', category: 'productivity')]
class UploadToOneDriveTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'upload_to_onedrive',
            description: 'Uploads a document created by generate_office_document to OneDrive or updates an existing '
                . 'OneDrive item. Use document_id from the generator response. For a new upload provide target_folder; '
                . 'for an update provide existing_item_id.',
        );
    }

    /** @return array<int, ToolProperty> */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'document_id',
                type: PropertyType::STRING,
                description: 'Generated document identifier returned by generate_office_document; never use the local path.',
                required: true,
            ),
            new ToolProperty(
                name: 'existing_item_id',
                type: PropertyType::STRING,
                description: 'OneDrive DriveItem ID to overwrite. When supplied, target_folder is ignored.',
                required: false,
            ),
            new ToolProperty(
                name: 'target_folder',
                type: PropertyType::STRING,
                description: 'Destination OneDrive folder for a new upload, for example Contratos or Cotizaciones. Required when existing_item_id is absent.',
                required: false,
            ),
            new ToolProperty(
                name: 'one_drive_user',
                type: PropertyType::STRING,
                description: 'Optional Microsoft 365 UPN/email that owns the target OneDrive. Defaults to the current Kanvas user.',
                required: false,
            ),
            new ToolProperty(
                name: 'link_type',
                type: PropertyType::STRING,
                description: 'Share link permission. Defaults to view.',
                required: false,
                enum: ['view', 'edit'],
            ),
            new ToolProperty(
                name: 'link_scope',
                type: PropertyType::STRING,
                description: 'Share link audience. Defaults to organization.',
                required: false,
                enum: ['organization', 'anonymous'],
            ),
        ];
    }

    public function __invoke(
        string $document_id,
        ?string $existing_item_id = null,
        ?string $target_folder = null,
        ?string $one_drive_user = null,
        string $link_type = 'view',
        string $link_scope = 'organization',
    ): string {
        if (! $this->hasTenantContext()) {
            throw new RuntimeException('OneDrive upload requires an app and company context.');
        }

        if (blank($existing_item_id) && blank($target_folder)) {
            throw new InvalidArgumentException('Provide existing_item_id to update a file or target_folder for a new upload.');
        }

        $userPrincipalName = filled($one_drive_user)
            ? $one_drive_user
            : $this->contextUser()?->getEmail();

        if (blank($userPrincipalName)) {
            throw new RuntimeException('No Microsoft 365 UPN/email is available for the target OneDrive.');
        }

        $localPath = (new GeneratedDocumentStore())->path($document_id);

        try {
            $uploader = new OneDriveDocumentUploader(
                (new OneDriveGraphClientFactory($this->app, $this->company))->make(),
                $userPrincipalName,
            );
            $item = $uploader->upload($localPath, $existing_item_id, $target_folder);
            $itemId = $item->getId();

            if (blank($itemId)) {
                throw new RuntimeException('Microsoft Graph uploaded the document but did not return a DriveItem ID.');
            }

            $shareUrl = $uploader->createShareLink($itemId, $link_type, $link_scope);
        } catch (ApiException $exception) {
            throw $this->translateGraphError($exception, $userPrincipalName);
        }

        return json_encode([
            'url' => $shareUrl,
            'item_id' => $itemId,
            'one_drive_user' => $userPrincipalName,
        ], JSON_THROW_ON_ERROR);
    }

    private function translateGraphError(ApiException $exception, string $userPrincipalName): RuntimeException
    {
        return match ($exception->getResponseStatusCode()) {
            401 => new RuntimeException('Microsoft Graph credentials are invalid or expired for this company.', previous: $exception),
            403 => new RuntimeException(
                "Microsoft Graph lacks permission to access OneDrive for '{$userPrincipalName}'. "
                . 'Confirm Files.ReadWrite.All application permission and tenant admin consent.',
                previous: $exception,
            ),
            404 => new RuntimeException(
                "OneDrive for '{$userPrincipalName}' is unavailable, or the existing_item_id is invalid.",
                previous: $exception,
            ),
            default => new RuntimeException('Microsoft Graph error: ' . $exception->getMessage(), previous: $exception),
        };
    }
}
