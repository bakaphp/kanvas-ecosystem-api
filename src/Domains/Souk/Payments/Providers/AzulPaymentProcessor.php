<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

class AzulPaymentProcessor
{
    protected AzulService $service;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected array $params = []
    ) {
        $this->service = new AzulService($this->app, $this->company);
    }

    public function charge(Payments $payment, Order $order): array
    {
        try {
            $paymentMethod = $payment->paymentMethod;
            $dataVaultToken = $paymentMethod->getMetadata(CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value);

            $request = new AzulPaymentRequest(
                channel: $this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? 'EC',
                store: $this->app->get(ConfigurationEnum::AZUL_STORE->value) ?? '',
                trxType: TransactionTypeEnum::SALE,
                amount: $this->toAzulAmount($order->getTotalAmount()),
                itbis: $this->toAzulAmount($order->getTotalTaxAmount()),
                customOrderId: (string) $order->id,
                cardNumber: $dataVaultToken ? null : $paymentMethod->getMetadata('card_number'),
                expiration: $dataVaultToken ? null : $paymentMethod->getMetadata('expiration'),
                cvc: $dataVaultToken ? null : $paymentMethod->getMetadata('cvc'),
                dataVaultToken: $dataVaultToken,
                saveToDataVault: $dataVaultToken ? '0' : '1',
            );

            $response = $this->service->sale($request);

            $order->set(CustomFieldEnum::AZUL_ORDER_ID->value, $response->azulOrderId);
            $order->set(CustomFieldEnum::AZUL_AUTHORIZATION_CODE->value, $response->authorizationCode);
            $order->set(CustomFieldEnum::AZUL_TICKET->value, $response->ticket);

            if ($response->dataVaultToken && ! $dataVaultToken) {
                $paymentMethod->metadata = array_merge(
                    $paymentMethod->metadata ?? [],
                    [CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value => $response->dataVaultToken]
                );
                $paymentMethod->save();
            }

            $payment->markAsPaid([
                'data' => [
                    'azul_order_id' => $response->azulOrderId,
                    'authorization_code' => $response->authorizationCode,
                    'response_message' => $response->responseMessage,
                    'ticket' => $response->ticket,
                ],
            ]);

            $payment->addLog('azul_charge_success', [
                'order_id' => $order->id,
                'azul_order_id' => $response->azulOrderId,
                'amount' => $order->getTotalAmount(),
            ]);

            return [
                'status' => 'success',
                'message' => 'Payment successful: ' . $response->responseMessage,
                'azul_order_id' => $response->azulOrderId,
                'data' => $response->toArray(),
            ];
        } catch (AzulException $e) {
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->addMetadata([
                'data' => [
                    'error' => $e->getMessage(),
                    'azul_error_body' => $e->getErrorBody(),
                ],
            ]);
            $payment->save();

            $payment->addLog('azul_charge_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => $e->getErrorBody(),
            ];
        } catch (Throwable $e) {
            report($e);

            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->addMetadata([
                'data' => ['error' => $e->getMessage()],
            ]);
            $payment->save();

            $payment->addLog('azul_charge_error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function refund(Payments $payment, Order $order): array
    {
        try {
            $azulOrderId = $order->get(CustomFieldEnum::AZUL_ORDER_ID->value);

            if (empty($azulOrderId)) {
                throw new AzulException('AzulOrderId not found on order. Cannot process refund.', 0, null, []);
            }

            $response = $this->service->refund(
                azulOrderId: (string) $azulOrderId,
                amount: $this->toAzulAmount($order->getTotalAmount()),
                itbis: $this->toAzulAmount($order->getTotalTaxAmount()),
                customOrderId: (string) $order->id,
            );

            $payment->status = PaymentStatusEnum::REVERSED->value;
            $payment->addMetadata([
                'data' => [
                    'refund_azul_order_id' => $response->azulOrderId,
                    'refund_ticket' => $response->ticket,
                    'response_message' => $response->responseMessage,
                ],
            ]);
            $payment->save();

            $payment->addLog('azul_refund_success', [
                'order_id' => $order->id,
                'original_azul_order_id' => $azulOrderId,
                'refund_azul_order_id' => $response->azulOrderId,
            ]);

            return [
                'status' => 'success',
                'message' => 'Refund successful: ' . $response->responseMessage,
                'refund_azul_order_id' => $response->azulOrderId,
                'data' => $response->toArray(),
            ];
        } catch (AzulException $e) {
            $payment->addLog('azul_refund_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => $e->getErrorBody(),
            ];
        } catch (Throwable $e) {
            report($e);

            $payment->addLog('azul_refund_error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    protected function toAzulAmount(float $amount): string
    {
        return (string) (int) round($amount * 100);
    }
}
