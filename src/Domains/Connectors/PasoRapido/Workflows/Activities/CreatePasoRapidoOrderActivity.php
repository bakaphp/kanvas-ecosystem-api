<?php

namespace Kanvas\Connectors\PasoRapido\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CreatePasoRapidoOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::PASO_RAPIDO,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $createPasoRapidoOrderAction = new CreatePasoRapidoOrderAction($app, $order);
                return $createPasoRapidoOrderAction->execute();
            },
            company: $order->company,
        );
    }
}
