<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Connectors\Apollo\Services\ApolloRateLimitService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Apollo Enrich Person',
    description: 'Looks the person up in Apollo and writes back whatever it finds — job title, employer, '
        . 'seniority, contact details. Enrichment only: it reads an external directory and updates the '
        . 'Kanvas record, and contacts nobody. Rate-limited, so a burst of records is spread out rather '
        . 'than rejected.',
    integration: IntegrationsEnum::APOLLO,
)]
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
                $rateLimit = new ApolloRateLimitService();

                if ($rateLimit->hasReachedDailyLimit($people->company)) {
                    return $this->limitReachedResponse($people);
                }

                if ($rateLimit->hasBeenScreenedRecently($people)) {
                    return $this->alreadyScreenedResponse($people);
                }

                return new EnrichPeopleFromApolloAction($people, $app)->execute();
            },
            company: $people->company,
        );
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
