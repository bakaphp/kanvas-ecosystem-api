<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Odoo\Actions\PushOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(name: 'OdooPushOrganizationActivity')]
class PushOrganizationActivity extends KanvasActivity implements WorkflowActivityInterface
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
            integration: IntegrationsEnum::ODOO,
            additionalParams: $params,
            integrationOperation: fn ($organization, $app, $integrationCompany, $additionalParams) => new PushOrganizationAction($organization)->execute(),
            company: $organization->company,
        );
    }
}
