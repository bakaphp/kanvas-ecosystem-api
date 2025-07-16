<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Spatie\Activitylog\Models\Activity;

class GenerateLeadLinkedFieldActivity extends KanvasActivity
{
    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $linkedField = Activity::where('subject_type', Lead::class)
                    ->where('subject_id', $lead->id)
                    ->where('log_name', 'linkedfield')
                    ->latest()
                    ->first();

                $hasLinkedField = $linkedField && $linkedField->properties->isNotEmpty();

                // Get existing data from the linkedfield activity log
                $existingData = $hasLinkedField ? $linkedField->properties->toArray() : [];

                // Make HTTP call to fetch new linked field data
                $newData = $this->fetchLinkedFieldData($lead, $app);

                if (empty($newData)) {
                    return [
                        'error' => 'Failed to fetch linked field data from API',
                        'lead_uuid' => $lead->uuid,
                    ];
                }

                // Merge data with existing taking priority
                $mergedData = array_merge($newData, $existingData);

                // Update or create the activity log entry
                if ($linkedField) {
                    $linkedField->update([
                        'properties' => $mergedData,
                    ]);
                } else {
                    Activity::create([
                        'log_name' => 'linkedfield',
                        'description' => 'Lead linked field data',
                        'subject_type' => Lead::class,
                        'subject_id' => $lead->id,
                        'causer_type' => get_class($app),
                        'causer_id' => $app->getId(),
                        'properties' => $mergedData,
                    ]);
                }

                return [
                    'success' => true,
                    'lead_uuid' => $lead->uuid,
                    'company_uuid' => $lead->company->uuid,
                    'merged_fields_count' => count($mergedData),
                    'new_fields_count' => count($newData),
                    'existing_fields_count' => count($existingData),
                    'updated_at' => now()->toISOString(),
                ];
            },
            company: $lead->company,
        );
    }

    /**
     * Fetch linked field data from the external API
     */
    private function fetchLinkedFieldData(Lead $lead, AppInterface $app): array
    {
        // Get the base URL from app settings
        $baseUrl = $app->get('sales_assist_api_url');

        if (empty($baseUrl)) {
            throw new Exception('Sales Assist API URL not configured in app settings (sales_assist_api_url)');
        }

        $client = new GuzzleClient([
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        /**
         * @todo move this logic inside this activity
         */
        $url = sprintf(
            '%s/v2/leads/%s/pdf-linked-fields?key=%s',
            rtrim($baseUrl, '/'), // Remove trailing slash if present
            $lead->uuid,
            $lead->company->uuid
        );

        $response = $client->get($url);

        if ($response->getStatusCode() !== 200) {
            throw new Exception('API returned status code: ' . $response->getStatusCode());
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        return $data ?: [];
    }
}
