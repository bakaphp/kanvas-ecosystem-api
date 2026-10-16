<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\UploadOrderDocumentsAction;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class UploadInsuranceDocumentsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (empty($order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value))) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order has no insurance quote number',
            ];
        }

        $provider = InsuranceProviderFactory::forOrder($order);

        if (! $provider instanceof InspectionProviderInterface) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => $provider->name() . ' does not accept documents',
            ];
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($provider) {
                $result = new UploadOrderDocumentsAction($order, $provider)->execute();

                return [
                    'order' => $order->getId(),
                    'status' => $result->uploaded > 0 ? 'success' : 'skipped',
                    'message' => $result->message,
                    'uploaded' => $result->uploaded,
                ];
            },
            company: $order->company,
        );
    }
}
