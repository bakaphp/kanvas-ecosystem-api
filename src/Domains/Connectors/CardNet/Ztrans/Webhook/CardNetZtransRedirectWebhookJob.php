<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Webhook;

use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'CardNet Ztrans 3DS Challenge Redirect',
    description: 'Part of the CardNet Ztrans 3-D Secure card flow, not something to attach by choice: the card '
        . 'issuer redirects the cardholder here after the challenge. It only records that the challenge '
        . 'returned and tells the web page or mobile WebView to finalize; it never decides the outcome.',
    integration: IntegrationsEnum::CARDNET_ZTRANS,
)]
class CardNetZtransRedirectWebhookJob extends ProcessWebhookJob
{
    private const string MESSAGE_TYPE = '3ds_complete';
    private const string META_RETURNED_AT = 'ztrans_3ds_challenge_returned_at';
    private const string META_RESULT = 'ztrans_3ds_challenge_result';

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload ?? [];
        $payment = $this->findPayment($this->resolvePaymentId($payload));

        if ($payment === null) {
            return [
                'message' => 'No payment found for the 3DS redirect',
                'html_response' => $this->notifyHtml('error', null),
            ];
        }

        $payment->addMetadata(['data' => [
            self::META_RETURNED_AT => now()->toIso8601String(),
            self::META_RESULT => $this->shortScalar($payload['result'] ?? null),
        ]]);
        $payment->save();

        $payment->addLog('ztrans_3ds_redirect_received', [
            'payment_id' => $payment->id,
            'fields' => array_keys($payload),
        ]);

        return [
            'message' => '3DS challenge returned; awaiting finalization',
            'html_response' => $this->notifyHtml('success', (int) $payment->id),
        ];
    }

    private function findPayment(?int $paymentId): ?Payments
    {
        if ($paymentId === null) {
            return null;
        }

        return Payments::query()
            ->where('apps_id', $this->receiver->apps_id)
            ->where('companies_id', $this->receiver->companies_id)
            ->where('id', $paymentId)
            ->first();
    }

    private function resolvePaymentId(array $payload): ?int
    {
        $queryString = parse_url((string) $this->webhookRequest->url, PHP_URL_QUERY);
        parse_str(is_string($queryString) ? $queryString : '', $query);

        $candidate = $query['payment_id'] ?? $payload['payment_id'] ?? $this->paymentIdFromEcho($payload['rd'] ?? null);

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    private function shortScalar(mixed $value): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 32) : null;
    }

    private function paymentIdFromEcho(mixed $echo): ?string
    {
        $decoded = is_string($echo) ? base64_decode($echo, true) : false;

        if ($decoded === false) {
            return null;
        }

        parse_str($decoded, $fields);

        return isset($fields['payment_id']) ? (string) $fields['payment_id'] : null;
    }

    private function notifyHtml(string $status, ?int $paymentId): string
    {
        $message = json_encode(
            ['type' => self::MESSAGE_TYPE, 'status' => $status, 'payment_id' => $paymentId],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><script>'
            . 'var message = ' . $message . ';'
            . 'window.parent.postMessage(message, "*");'
            . 'if (window.ReactNativeWebView) { window.ReactNativeWebView.postMessage(JSON.stringify(message)); }'
            . '</script></body></html>';
    }
}
