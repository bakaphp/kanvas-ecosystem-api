<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\RespondIO\Actions\PushLeadAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $company = $lead->company;

        $bearerToken = $company->get(ConfigurationEnum::BEARER_TOKEN->value)
            ?? $app->get(ConfigurationEnum::BEARER_TOKEN->value);

        if (! $bearerToken) {
            return [
                'error' => 'Respond.io bearer token not configured for this company or app',
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::RESPOND_IO,
            additionalParams: $params,
            integrationOperation: function (Lead $lead): array {
                $response = new PushLeadAction(lead: $lead)->execute();

                return [
                    'message' => 'Lead pushed to Respond.io successfully',
                    'contact' => $response,
                ];
            },
            company: $company,
        );
    }
}
