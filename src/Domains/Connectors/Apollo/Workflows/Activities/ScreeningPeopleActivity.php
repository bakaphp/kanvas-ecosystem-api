<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class ScreeningPeopleActivity extends KanvasActivity
{
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::APOLLO,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) {
                if ($this->hasReachedLimit($people)) {
                    return $this->limitReachedResponse($people);
                }

                if ($this->hasBeenScreenedRecently($people)) {
                    return $this->alreadyScreenedResponse($people);
                }

                return new EnrichPeopleFromApolloAction($people, $app)->execute();
            },
            company: $people->company,
        );
    }

    private function hasReachedLimit(Model $people): bool
    {
        $todayReport = $this->getTodayReport($people);

        return $todayReport[date('Y-m-d')]['total'] >= 2000;
    }

    private function hasBeenScreenedRecently(Model $people): bool
    {
        $key = ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value;
        $apolloRevalidationThreshold = $people->company->get(ConfigurationEnum::APOLLO_REVALIDATION->value) ?? '-2 months';

        return $people->get($key) && $people->get($key) > strtotime($apolloRevalidationThreshold);
    }

    private function getTodayReport(Model $people): array
    {
        $report = $people->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? [];

        if (! isset($report[date('Y-m-d')])) {
            $report[date('Y-m-d')] = ['total' => 0, 'success' => 0, 'processed' => 0, 'failed' => 0];
        }

        return $report;
    }

    private function limitReachedResponse(Model $people): array
    {
        return [
            'status' => 'failed',
            'message' => 'Limit reached',
            'people_id' => $people->id,
            'data' => [],
        ];
    }

    private function alreadyScreenedResponse(Model $people): array
    {
        return [
            'status' => 'success',
            'message' => 'People already screened',
            'people_id' => $people->id,
            'data' => [],
        ];
    }
}
