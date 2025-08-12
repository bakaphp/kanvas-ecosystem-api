<?php

namespace Kanvas\Connectors\PasoRapido\Actions;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Throwable;

class CreatePasoRapidoOrderAction
{
    public function __construct(
        protected Apps $app,
        protected Order $order,
    ) {
    }

    public function execute(): array
    {
        if (! isset($this->order->metadata['data']['paso_rapido_tag'])) {
            return [
                'order' => $this->order->getId(),
                'status' => 'error',
                'message' => 'Paso Rapido tag not found',
            ];
        }

        if ($this->order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value) === PaymentStatusEnum::PAID->value) {
            return [
                'status' => 'success',
                'message' => 'Payment already confirmed',
                'data' => [
                    'order' => $this->order->getId(),
                ],
            ];
        }

        $tag = $this->order->metadata['data']['paso_rapido_tag'];

        try {
            $pasoRapidoService = new PasoRapidoService($this->app, $this->order->company);
            $intentId = $this->order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);
            $bankTransaction = explode(':', $intentId)[1];
            $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                reference: $tag,
                bankTransaction: $bankTransaction,
                amount: $this->order->getTotalAmount(),
                fiscalCredit: false,
                dni: $this->order->get(CustomFieldEnum::PASO_RAPIDO_DNI->value) ?? "",
            ));

            if ($confirmPaymentResponse->tag) {
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::PAID->value);
                $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode($confirmPaymentResponse->toArray()));
                $this->order->set(EchoPayCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);

                return [
                    'status' => 'success',
                    'message' => 'Payment confirmed',
                    'data' => [
                        'order' => $this->order->getId(),
                        'tag' => $tag
                    ],
                ];
            }

            // If no tag returned, treat as failure
            throw new Exception('No tag returned from payment confirmation');
        } catch (Throwable $e) {
            if ($e instanceof RequestException) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $errorMessage = json_decode((string) $response->getBody())->descripcionMensaje;
                } else {
                    $errorMessage = $e->getMessage();
                }
            } else {
                $errorMessage = $e->getMessage();
            }

            $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value, PaymentStatusEnum::FAILED->value);
            $this->order->set(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value, json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));
            $this->order->set(EchoPayCustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 0);

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'data' => [
                    'tag' => $tag,
                    'order' => $this->order->getId(),
                    'report' => 'fail',
                    'trace' => $e->getTraceAsString(),
                ],
            ];
        }
    }
}
