<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Actions;

use GuzzleHttp\Psr7\Utils;
use Kanvas\Connectors\Lendflow\Client;
use Kanvas\Connectors\Lendflow\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Deals\Models\Deal;

class UploadDealFilesAction
{
    public function __construct(
        protected readonly Deal $deal,
    ) {
    }

    public function execute(): array
    {
        $applicationId = (string) ($this->deal->get(CustomFieldEnum::LENDFLOW_APPLICATION_ID->value) ?? '');
        if ($applicationId === '') {
            throw new ValidationException('Deal has no Lendflow application id. Submit the application first.');
        }

        $files = $this->deal->getFiles();
        if ($files->isEmpty()) {
            return [
                'message' => 'No files attached to deal',
                'uploaded' => 0,
                'application_id' => $applicationId,
            ];
        }

        $multipartFiles = [];
        foreach ($files as $entity) {
            $url = (string) ($entity->url ?? '');
            if ($url === '') {
                continue;
            }

            $stream = Utils::tryFopen($url, 'r');
            $multipartFiles[] = [
                'name' => (string) ($entity->name ?? ('file-' . (string) $entity->id)),
                'contents' => $stream,
            ];
        }

        if ($multipartFiles === []) {
            return [
                'message' => 'No resolvable files to upload',
                'uploaded' => 0,
                'application_id' => $applicationId,
            ];
        }

        $client = new Client($this->deal->app, $this->deal->company);
        $response = $client->uploadMultipart(
            '/api/v2/applications/' . $applicationId . '/files/multiple',
            $multipartFiles,
        );

        $uploadedHistory = $this->deal->get(CustomFieldEnum::LENDFLOW_UPLOADED_FILES->value);
        $uploadedHistory = is_array($uploadedHistory) ? $uploadedHistory : [];
        $uploadedHistory[] = [
            'date' => date('Y-m-d H:i:s'),
            'application_id' => $applicationId,
            'file_count' => count($multipartFiles),
            'response' => $response,
        ];
        $this->deal->set(CustomFieldEnum::LENDFLOW_UPLOADED_FILES->value, $uploadedHistory);

        return [
            'message' => 'Files uploaded to Lendflow',
            'uploaded' => count($multipartFiles),
            'application_id' => $applicationId,
            'response' => $response,
        ];
    }
}
