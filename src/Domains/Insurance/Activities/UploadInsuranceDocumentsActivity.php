<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\UploadOrderDocumentsAction;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Insurance Upload Documents',
    description: 'Pushes the inspection documents attached to an insurance order (vehicle photos and papers) to the insurer. '
        . 'Use it when a file is attached to the order, for insurers that inspect the vehicle before emitting; it is a no-op for an insurer that takes no documents or for an order without a quote number. '
        . 'It only uploads: it does not change the order status, does not charge anyone and does not contact the customer. '
        . 'Do NOT use it to send the policy documents to the customer; that is the policy email step.',
)]
class UploadInsuranceDocumentsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return InsuranceOrderOriginEnum::purchaseFlowSkipped($order);
        }

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
