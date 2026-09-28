<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Odoo\Actions\PushLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(name: 'OdooPushLeadActivity')]
class PushLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param Lead $lead
     */
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::ODOO,
            additionalParams: $params,
            integrationOperation: fn ($lead, $app, $integrationCompany, $additionalParams) => new PushLeadAction($lead)->execute(),
            company: $lead->company,
        );
    }
}
