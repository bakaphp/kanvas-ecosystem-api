<?php

namespace Kanvas\Connectors\PasoRapido\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

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

                if ($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value) === PaymentStatusEnum::PAID->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Payment already confirmed',
                    ];
                }

                $tag = $order->metadata['data']['paso_rapido_tag'];

                try {
                    $pasoRapidoService = new PasoRapidoService($app, $order->company);
                    $intentId = $order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
                    $bankTransaction = explode(':', $intentId)[1];
                    $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                        reference: $tag,
                        bankTransaction: $bankTransaction,
                        amount: $order->getTotalAmount(),
                        fiscalCredit: false,
                        dni: $order->get(CustomFieldEnum::PASO_RAPIDO_DNI->value) ?? "",
                    ));

                    if ($confirmPaymentResponse->tag) {
                        $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);
                        $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode($confirmPaymentResponse->toArray()));
                    }

                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'tag' => $tag,
                        'message' => 'Payment confirmed',
                    ];
                } catch (Throwable $e) {
                    $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
                    $order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode($e->getMessage()));

                    return [
                        'order' => $order->getId(),
                        'status' => 'error',
                        'tag' => $tag,
                        'message' => $e->getMessage(),
                        'report' => 'fail',
                        'trace' => $e->getTraceAsString(),
                    ];
                }
            },
            company: $order->company,
        );
    }
}
