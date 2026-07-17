<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\PushDealAction;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class PushDealActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param Deal $deal
     */
    #[Override]
    public function execute(Model $deal, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $deal,
            app: $app,
            integration: IntegrationsEnum::SALESFORCE,
            additionalParams: $params,
            integrationOperation: fn ($deal, $app, $integrationCompany, $additionalParams) => new PushDealAction($deal)->execute(),
            company: $deal->company,
        );
    }
}
