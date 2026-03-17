<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Webhook;

use Exception;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Handles the ACS POST-back to the TermUrl after a 3DS challenge.
 *
 * Azul's ACS server POSTs to this URL when the cardholder completes (or fails)
 * authentication. The payment_id is embedded in the TermUrl query string by
 * AzulProcessor::build3DSSaleRequest() so we can identify the payment.
 *
 * Expected payload fields:
 *   - PaRes  (3DS v1) or cRes (3DS v2): the authentication result from the ACS
 *   - MD     (3DS v1 only): optional merchant data fallback
 *
 * The PaRes/cRes is stored in payment metadata so that when the frontend
 * calls the `finalizePaymentChallenge` mutation, the data is already there.
 */
class AzulTermUrlWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload   = $this->webhookRequest->payload;
        $paymentId = $this->getPaymentIdFromUrl() ?? $payload['MD'] ?? null;

        if (! $paymentId) {
            return [
                'message' => 'No payment identifier found in callback',
                'html'    => $this->notifyParent('error', 'No payment identifier'),
            ];
        }

        $payment = Payments::where([
            'apps_id' => $this->receiver->app->getId(),
            'id'      => (int) $paymentId,
        ])->first();

        if (! $payment) {
            throw new Exception('Payment not found for 3DS callback: ' . $paymentId);
        }

        // Azul may send the field in different casings depending on the flow
        $paRes = $payload['PaRes'] ?? $payload['cRes'] ?? $payload['cres'] ?? $payload['pares'] ?? null;
        $type  = match (true) {
            isset($payload['cRes']), isset($payload['cres']) => 'cRes',
            default => 'PaRes',
        };

        $sessionData = $payload['threeDSSessionData'] ?? $payload['ThreeDSSessionData'] ?? null;

        $payment->addMetadata([
            'data' => [
                '3ds_pa_res'          => $paRes,
                '3ds_pa_res_type'     => $type,
                '3ds_session_data'    => $sessionData,
            ],
        ]);
        $payment->save();

        $payment->addLog('3ds_term_url_received', [
            'payment_id'  => $payment->id,
            'has_pa_res'  => ! empty($paRes),
            'fields'      => array_keys($payload), // field names only (PCI)
        ]);

        return [
            'message' => '3DS challenge response stored — awaiting finalization',
            'html'    => $this->notifyParent('success', '3DS authentication received'),
        ];
    }

    private function getPaymentIdFromUrl(): ?string
    {
        $url = $this->webhookRequest->url ?? '';
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query['payment_id'] ?? null;
    }

    private function notifyParent(string $status, string $message): string
    {
        return '<script>window.parent.postMessage({type: "3ds_complete", status: "' . $status . '", message: "' . addslashes($message) . '"}, "*"); window.close();</script>';
    }
}
