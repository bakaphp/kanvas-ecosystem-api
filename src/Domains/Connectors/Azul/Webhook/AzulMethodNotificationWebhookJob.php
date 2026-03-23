<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Webhook;

use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Handles the 3DS method notification POST to the MethodNotificationUrl.
 *
 * Azul's 3DS server POSTs here after the 3DS method iframe collects browser
 * fingerprint data. The payload contains a base64-encoded `threeDSMethodData`
 * JSON with the `threeDSServerTransID`.
 *
 * The `payment_id` query param is appended to the MethodNotificationUrl by the
 * processor when building the 3DS sale request, so we can identify and update the payment.
 *
 * The response HTML notifies the top-level window via postMessage so the
 * frontend knows the fingerprinting step is complete and can call
 * startChallenge again with the azulOrderId to proceed to the challenge step.
 */
class AzulMethodNotificationWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $methodData = $this->webhookRequest->payload['threeDSMethodData'] ?? null;
        $transId    = null;

        if (! empty($methodData)) {
            $decoded = json_decode(base64_decode($methodData), true);
            $transId = $decoded['threeDSServerTransID'] ?? null;
        }

        $paymentId = $this->getPaymentIdFromUrl();

        if ($paymentId && $transId) {
            $payment = Payments::where([
                'apps_id' => $this->receiver->app->getId(),
                'id'      => (int) $paymentId,
            ])->first();

            if ($payment) {
                $payment->addMetadata([
                    'data' => [
                        '3ds_server_trans_id'          => $transId,
                        '3ds_method_notification_status' => 'RECEIVED',
                    ],
                ]);
                $payment->save();

                $payment->addLog('3ds_method_notification_received', [
                    'payment_id'            => $payment->id,
                    '3ds_server_trans_id'   => $transId,
                ]);
            }
        }

        return [
            'message'  => '3DS method notification received for payment_id: ' . ($paymentId ?? 'unknown'),
            'received' => ! empty($methodData),
            'html'     => $this->notifyParent($transId),
        ];
    }

    private function getPaymentIdFromUrl(): ?string
    {
        $url = $this->webhookRequest->url ?? '';
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query['payment_id'] ?? null;
    }

    private function notifyParent(?string $transId): string
    {
        $payload = json_encode([
            'type'                 => '3ds_method_complete',
            'threeDSServerTransID' => $transId,
        ]);

        return '<script>window.top.postMessage(' . $payload . ', "*");</script>';
    }
}
