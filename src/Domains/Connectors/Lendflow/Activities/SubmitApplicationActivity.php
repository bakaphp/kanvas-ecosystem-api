<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Lendflow\Actions\SubmitApplicationAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class SubmitApplicationActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Deal $deal, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $deal,
            app: $app,
            integration: IntegrationsEnum::LENDFLOW,
            additionalParams: $params,
            integrationOperation: function (Deal $deal) {
                $result = new SubmitApplicationAction($deal)->execute();

                return [
                    'message' => 'Lendflow application submitted',
                    'application_id' => $result['application_id'],
                    'deal_id' => $deal->getId(),
                ];
            },
            company: $deal->company,
        );
    }
}
