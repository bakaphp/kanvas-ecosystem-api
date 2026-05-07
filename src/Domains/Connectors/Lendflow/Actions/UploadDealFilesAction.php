<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Actions;

use GuzzleHttp\Psr7\Utils;
use Kanvas\Connectors\Lendflow\Client;
use Kanvas\Connectors\Lendflow\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum as ZohoCustomFieldEnum;
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
        $applicationId = $this->resolveApplicationId();
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

        // Per-application dedup map: { application_id => [filesystem_id, ...] }
        $uploadedMap = $this->deal->get(CustomFieldEnum::LENDFLOW_UPLOADED_FILE_IDS->value);
        $uploadedMap = is_array($uploadedMap) ? $uploadedMap : [];
        $alreadyUploaded = is_array($uploadedMap[$applicationId] ?? null) ? $uploadedMap[$applicationId] : [];

        $multipartFiles = [];
        $newlyUploadedIds = [];
        foreach ($files as $entity) {
            $fileId = (int) ($entity->id ?? 0);
            if ($fileId <= 0 || in_array($fileId, $alreadyUploaded, true)) {
                continue;
            }
            $url = (string) ($entity->url ?? '');
            if ($url === '') {
                continue;
            }

            $stream = Utils::tryFopen($url, 'r');
            $multipartFiles[] = [
                'name' => (string) ($entity->name ?? ('file-' . (string) $fileId)),
                'contents' => $stream,
            ];
            $newlyUploadedIds[] = $fileId;
        }

        if ($multipartFiles === []) {
            return [
                'message' => 'No new files to upload — all already sent for application ' . $applicationId,
                'uploaded' => 0,
                'application_id' => $applicationId,
            ];
        }

        $client = new Client($this->deal->app, $this->deal->company);
        $response = $client->uploadMultipart(
            '/api/v2/applications/' . $applicationId . '/files/multiple',
            $multipartFiles,
        );

        $uploadedMap[$applicationId] = array_values(
            array_unique(
                array_merge($alreadyUploaded, $newlyUploadedIds)
            )
        );
        $this->deal->set(CustomFieldEnum::LENDFLOW_UPLOADED_FILE_IDS->value, $uploadedMap);

        $uploadedHistory = $this->deal->get(CustomFieldEnum::LENDFLOW_UPLOADED_FILES->value);
        $uploadedHistory = is_array($uploadedHistory) ? $uploadedHistory : [];
        $uploadedHistory[] = [
            'date' => date('Y-m-d H:i:s'),
            'application_id' => $applicationId,
            'file_count' => count($multipartFiles),
            'file_ids' => $newlyUploadedIds,
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

    protected function resolveApplicationId(): string
    {
        $applicationId = (string) ($this->deal->get(CustomFieldEnum::LENDFLOW_APPLICATION_ID->value) ?? '');
        if ($applicationId !== '') {
            return $applicationId;
        }

        $zohoRaw = $this->deal->get(ZohoCustomFieldEnum::ZOHO_DEAL_RAW->value);
        if (! is_array($zohoRaw)) {
            return '';
        }

        $lendflowResponse = $zohoRaw['Lendflow_Response'] ?? null;
        if (! is_string($lendflowResponse) || $lendflowResponse === '') {
            return '';
        }

        $decoded = json_decode($lendflowResponse, true);
        if (! is_array($decoded)) {
            return '';
        }

        $found = $decoded['data']['application_id'] ?? $decoded['application_id'] ?? null;
        if (! is_string($found) || $found === '') {
            return '';
        }

        $this->deal->set(CustomFieldEnum::LENDFLOW_APPLICATION_ID->value, $found);

        return $found;
    }
}
