<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Actions;

use Kanvas\Connectors\Lendflow\Client;
use Kanvas\Connectors\Lendflow\Enums\CustomFieldEnum;
use Kanvas\Connectors\Lendflow\Services\LendflowService;
use Kanvas\Guild\Deals\Models\Deal;
use RuntimeException;

class SubmitApplicationAction
{
    public function __construct(
        protected readonly Deal $deal,
    ) {
    }

    public function execute(): array
    {
        $app = $this->deal->app;
        $company = $this->deal->company;

        $service = new LendflowService($app, $company);
        $client = new Client($app, $company);

        $payload = $service->buildApplicationPayload($this->deal);
        $response = $client->post('/api/v2/applications', $payload);

        $applicationId = $this->extractApplicationId($response);
        if ($applicationId === null) {
            throw new RuntimeException('Lendflow application submission did not return an application id. Response: ' . ((string) json_encode($response)));
        }

        $this->deal->set(CustomFieldEnum::LENDFLOW_APPLICATION_ID->value, $applicationId);

        $history = $this->deal->get(CustomFieldEnum::LENDFLOW_SUBMISSION_HISTORY->value);
        $history = is_array($history) ? $history : [];
        $history[] = [
            'date' => date('Y-m-d H:i:s'),
            'application_id' => $applicationId,
            'payload' => $payload,
            'response' => $response,
        ];
        $this->deal->set(CustomFieldEnum::LENDFLOW_SUBMISSION_HISTORY->value, $history);

        return [
            'application_id' => $applicationId,
            'response' => $response,
        ];
    }

    protected function extractApplicationId(array $response): ?string
    {
        foreach (['application_id', 'id'] as $topKey) {
            if (isset($response[$topKey]) && is_string($response[$topKey])) {
                return $response[$topKey];
            }
        }

        if (isset($response['data']) && is_array($response['data'])) {
            foreach (['application_id', 'id'] as $nestedKey) {
                if (isset($response['data'][$nestedKey]) && is_string($response['data'][$nestedKey])) {
                    return $response['data'][$nestedKey];
                }
            }
        }

        return null;
    }
}
