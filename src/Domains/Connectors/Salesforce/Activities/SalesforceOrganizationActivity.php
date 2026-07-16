<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Actions\SyncOrganizationToSalesforceAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class SalesforceOrganizationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param Organization $organization
     */
    #[Override]
    public function execute(Model $organization, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $organization,
            app: $app,
            integration: IntegrationsEnum::SALESFORCE,
            additionalParams: $params,
            integrationOperation: fn ($organization, $app, $integrationCompany, $additionalParams) => new SyncOrganizationToSalesforceAction($app, $organization)->execute(),
            company: $organization->company,
        );
    }
}
