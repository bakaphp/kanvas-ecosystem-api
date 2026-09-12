<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Odoo\Actions\PushPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(name: 'OdooPushPeopleActivity')]
class PushPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param People $people
     */
    #[Override]
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::ODOO,
            additionalParams: $params,
            integrationOperation: fn ($people, $app, $integrationCompany, $additionalParams) => new PushPeopleAction($people)->execute(),
            company: $people->company,
        );
    }
}
