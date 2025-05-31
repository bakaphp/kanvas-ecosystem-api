<?php

namespace Kanvas\Connectors\PasoRapido\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
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
                if (! isset($order->metadata['data']['paso_rapido_tag'])) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'error',
                        'message' => 'Paso Rapido tag not found',
                    ];
                }

                $tag = $order->metadata['data']['paso_rapido_tag'];

                $pasoRapidoService = new PasoRapidoService($app, $order->company);
                $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                    reference: $tag,
                    bankTransaction: $order->getPrivateMetadata('payment_transaction_id'),
                    amount: $order->getTotalAmount(),
                    fiscalCredit: false,
                    dni: $order->getPrivateMetadata('paso_rapido_dni') ?? "",
                ));

                if ($confirmPaymentResponse->tag) {
                    $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, 'paid');
                    $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, $confirmPaymentResponse);
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Payment confirmed',
                ];
            },
            company: $order->company,
        );
    }
}
