<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\CardNetZtrans;

use Baka\Support\IPInfo;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Ztrans\Concerns\FormatsZtransCardData;
use Kanvas\Connectors\CardNet\Ztrans\Concerns\ReadsZtransSettings;
use Kanvas\Connectors\CardNet\Ztrans\DataTransferObject\ZtransResponse;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransException;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransUnknownOutcomeException;
use Kanvas\Connectors\CardNet\Ztrans\Services\RedirectReceiverService;
use Kanvas\Connectors\CardNet\Ztrans\Support\ZtransMessage;
use Kanvas\Connectors\CardNet\Ztrans\ThreeDSServerClient;
use Kanvas\Connectors\CardNet\Ztrans\ZtransClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\CurrencyEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Support\AutoSubmitChallengeForm;
use Override;

final class CardNetZtransProcessor implements PaymentProcessorInterface, ThreeDSProcessorInterface
{
    use FormatsZtransCardData;
    use ReadsZtransSettings;

    private const string ENVIRONMENT = 'ECommerce';
    private const string CHALLENGE_REQUIRED = 'C';
    private const string AUTHENTICATED = 'Y';

    private const string META_IDEMPOTENCY_KEYS = 'ztrans_ikeys';
    private const string META_INTEGRATOR_TX_ID = 'ztrans_integrator_tx_id';
    private const string META_PURCHASE_AMOUNT = 'ztrans_purchase_amount';
    private const string META_SERVER_TRANS_ID = 'ztrans_3ds_server_trans_id';
    private const string META_USE_HOLD = 'ztrans_use_hold';
    private const string META_REFERENCE = 'ztrans_reference';
    private const string META_ERROR = 'ztrans_error';

    private const array TERMINAL_STATUSES = [
        PaymentStatusEnum::PAID,
        PaymentStatusEnum::AUTHORIZED,
        PaymentStatusEnum::FAILED,
        PaymentStatusEnum::CANCELLED,
        PaymentStatusEnum::REVERSED,
    ];

    public function __construct(
        Apps $app,
        private readonly ZtransClient $ztrans,
        private readonly ThreeDSServerClient $threeDSServer,
        private readonly RedirectReceiverService $redirectReceiver = new RedirectReceiverService(),
    ) {
        $this->app = $app;
    }

    #[Override]
    public function name(): string
    {
        return 'cardnet_ztrans';
    }

    #[Override]
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        throw new DomainException('CardNet Ztrans requires 3DS; call startPaymentChallengeWithCard instead.');
    }

    #[Override]
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        $this->assertSupportedCurrency($order);
        $card = $this->requireCard($context);

        $useHold = $this->resolveUseHold($payment, $context);
        $integratorTxId = (string) Str::uuid();
        $purchaseAmount = $this->minorUnits($order->getTotalDueAmount());

        $payment->addMetadata(['data' => [
            self::META_INTEGRATOR_TX_ID => $integratorTxId,
            self::META_PURCHASE_AMOUNT => $purchaseAmount,
            self::META_USE_HOLD => $useHold,
        ]]);
        $payment->save();

        try {
            $authentication = $this->threeDSServer->authenticate(
                $this->buildAuthenticationPayload(
                    $payment,
                    $order,
                    $card,
                    $context,
                    $integratorTxId,
                    $purchaseAmount
                )
            );
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_authentication_unreachable', ['error' => $e->getMessage()]);

            return $this->keepForRetry($payment, 'Card authentication is unavailable. Try again.');
        }

        $serverTransId = (string) ($authentication['threeDSServerTransID'] ?? '');
        $transStatus = (string) ($authentication['transStatus'] ?? '');

        if ($serverTransId !== '') {
            $payment->addMetadata(['data' => [self::META_SERVER_TRANS_ID => $serverTransId]]);
        }

        if ($transStatus === self::CHALLENGE_REQUIRED && $serverTransId !== '') {
            return $this->requireChallenge($payment, $authentication);
        }

        if ($transStatus === self::AUTHENTICATED && $serverTransId !== '') {
            return $this->settleAuthenticatedPayment($payment, $order, $card);
        }

        return $this->failChallenge(
            $payment,
            'authentication_rejected',
            'transStatus ' . $transStatus,
            'Card authentication failed.',
            ['trans_status' => $transStatus]
        );
    }

    #[Override]
    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        if ($this->isSettled($payment)) {
            return new ThreeDSResult(
                success: in_array($payment->status, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::AUTHORIZED->value], true),
                message: 'Payment already in state: ' . $payment->status,
                status: $payment->status,
                data: ['reference' => $payment->getMetadata(self::META_REFERENCE)],
            );
        }

        $card = $this->extractCard($context);

        if ($card === null) {
            return $this->keepForRetry($payment, 'Card details are required again to complete the sale after the challenge.');
        }

        $lock = Cache::lock('payment:' . $payment->id . ':finalize', 60);

        if (! $lock->get()) {
            return $this->keepForRetry($payment, 'Payment finalization is already in progress.');
        }

        try {
            return $this->settleAuthenticatedPayment($payment, $order, $card);
        } finally {
            $lock->release();
        }
    }

    #[Override]
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        if ($payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return new CaptureResult(
                success: true,
                message: 'Payment was not pre-authorized; no separate capture step needed.',
                transactionId: (string) $payment->payment_intent_id,
            );
        }

        $captureAmount = $amount ?? $order->getTotalDueAmount();

        try {
            $response = $this->ztrans->checkout([
                'idempotency-key' => $this->idempotencyKey($payment, 'checkout'),
                'environment' => self::ENVIRONMENT,
                'amount' => $captureAmount,
                'itbis' => $order->getTotalTaxAmount(),
                'client-ip' => $this->clientIp($order),
                'reference-number' => $this->referenceNumber($order),
                'approval-code' => (string) $payment->authorization_code,
                'txToken' => (string) $payment->payment_intent_id,
                'token' => (string) $payment->idempotency_key,
            ]);
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_capture_unreachable', ['error' => $e->getMessage()]);

            return new CaptureResult(success: false, message: 'Capture could not be completed. Try again.', transactionId: '');
        }

        if (! $response->isApproved()) {
            $payment->addLog('ztrans_capture_declined', $this->responseLog($response));

            return new CaptureResult(
                success: false,
                message: ZtransMessage::forCustomer($response),
                transactionId: '',
                raw: $response->body,
            );
        }

        $payment->markAsPaid(['data' => [self::META_REFERENCE => $response->reference ?: $payment->payment_intent_id]]);
        $payment->addLog('ztrans_capture_success', $this->responseLog($response));

        return new CaptureResult(
            success: true,
            message: $response->description,
            transactionId: (string) $payment->payment_intent_id,
            raw: $response->body,
        );
    }

    #[Override]
    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        if ($payment->status !== PaymentStatusEnum::PAID->value) {
            return new RefundResult(success: false, message: 'Only paid payments can be refunded.', transactionId: '');
        }

        $refundAmount = $amount ?? (float) $payment->amount;

        try {
            $response = $this->ztrans->refund([
                'idempotency-key' => $this->idempotencyKey($payment, 'refund'),
                'amount' => $refundAmount,
                'currency' => $this->currencyCode($order),
                'invoice-number' => $this->referenceNumber($order),
                'reference-number' => $this->referenceNumber($order),
                'client-ip' => $this->clientIp($order),
                'txToken' => (string) $payment->payment_intent_id,
                'token' => (string) $payment->idempotency_key,
            ]);
        } catch (ValidationException $e) {
            return new RefundResult(success: false, message: $e->getMessage(), transactionId: '');
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_refund_unreachable', ['error' => $e->getMessage()]);

            return new RefundResult(success: false, message: 'Refund could not be completed. Try again.', transactionId: '');
        }

        if (! $response->isApproved()) {
            $payment->addLog('ztrans_refund_declined', $this->responseLog($response));

            return new RefundResult(
                success: false,
                message: ZtransMessage::forCustomer($response),
                transactionId: '',
                raw: $response->body,
            );
        }

        if ($refundAmount >= (float) $payment->amount) {
            $payment->status = PaymentStatusEnum::REVERSED->value;
            $payment->save();
        }

        $payment->addLog('ztrans_refund_success', $this->responseLog($response));

        return new RefundResult(
            success: true,
            message: $response->description,
            transactionId: $response->reference,
            raw: $response->body,
        );
    }

    #[Override]
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        if (! in_array($payment->status, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::AUTHORIZED->value], true)) {
            return new VoidResult(success: false, message: 'Only paid or authorized payments can be voided.', transactionId: '');
        }

        try {
            $response = $this->ztrans->void([
                'idempotency-key' => $this->idempotencyKey($payment, 'void'),
                'environment' => self::ENVIRONMENT,
                'amount' => (float) $payment->amount,
                'currency' => $this->currencyCode($order),
                'pnRef' => (string) $payment->payment_intent_id,
                'token' => (string) $payment->idempotency_key,
            ]);
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_void_unreachable', ['error' => $e->getMessage()]);

            return new VoidResult(success: false, message: 'Void could not be completed. Try again.', transactionId: '');
        }

        if (! $response->isApproved()) {
            $payment->addLog('ztrans_void_declined', $this->responseLog($response));

            return new VoidResult(
                success: false,
                message: ZtransMessage::isVoidWindowClosed($response)
                    ? 'The daily batch is already closed; use a refund instead.'
                    : ZtransMessage::forCustomer($response),
                transactionId: '',
                raw: $response->body,
            );
        }

        $payment->status = PaymentStatusEnum::CANCELLED->value;
        $payment->save();
        $payment->addLog('ztrans_void_success', $this->responseLog($response));

        return new VoidResult(
            success: true,
            message: $response->description,
            transactionId: $response->reference,
            raw: $response->body,
        );
    }

    #[Override]
    public function verify(Payments $payment, Order $order): VerifyResult
    {
        throw new DomainException('CardNet Ztrans has no transaction inquiry endpoint; reconcile manually with the idempotency key stored on the payment.');
    }

    private function settleAuthenticatedPayment(Payments $payment, Order $order, array $card): ThreeDSResult
    {
        $serverTransId = (string) $payment->getMetadata(self::META_SERVER_TRANS_ID);

        if ($serverTransId === '') {
            return $this->failChallenge(
                $payment,
                'missing_server_trans_id',
                'No 3DS transaction on payment',
                'Card authentication was never started.'
            );
        }

        try {
            $status = $this->threeDSServer->status(
                threeDSServerTransId: $serverTransId,
                integratorTxId: (string) $payment->getMetadata(self::META_INTEGRATOR_TX_ID),
                purchaseAmount: (string) $payment->getMetadata(self::META_PURCHASE_AMOUNT)
            );
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_3ds_status_unreachable', ['error' => $e->getMessage()]);

            return $this->keepForRetry($payment, 'Card authentication status is unavailable. Try again.');
        }

        if (($status['transStatus'] ?? '') !== self::AUTHENTICATED) {
            return $this->failChallenge(
                $payment,
                'authentication_rejected',
                'transStatus ' . ($status['transStatus'] ?? ''),
                'Card authentication failed.',
                ['trans_status' => $status['transStatus'] ?? '']
            );
        }

        return $this->runSale($payment, $order, $card, $status);
    }

    private function runSale(Payments $payment, Order $order, array $card, array $authentication): ThreeDSResult
    {
        $useHold = (bool) $payment->getMetadata(self::META_USE_HOLD);
        $operation = $useHold ? 'checkin' : 'sale';

        try {
            $idempotencyKey = $this->idempotencyKey($payment, $operation);
        } catch (ZtransException $e) {
            $payment->addLog('ztrans_' . $operation . '_unreachable', ['error' => $e->getMessage()]);

            return $this->keepForRetry($payment, 'Payment could not be processed. Try again.');
        }

        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->save();

        $payload = [
            'idempotency-key' => $idempotencyKey,
            'environment' => self::ENVIRONMENT,
            'card-number' => $card['number'],
            'expiration-date' => $this->ztransExpiration($card['expiration_date']),
            'cvv' => $card['cvv'],
            'amount' => $order->getTotalDueAmount(),
            'tax' => $order->getTotalTaxAmount(),
            'tip' => 0,
            'currency' => $this->currencyCode($order),
            'invoice-number' => $this->referenceNumber($order),
            'reference-number' => $this->referenceNumber($order),
            'client-ip' => $this->clientIp($order),
            'token' => (string) $payment->idempotency_key,
            ...$this->threeDSFields($payment, $authentication),
        ];

        try {
            $response = $useHold ? $this->ztrans->checkin($payload) : $this->ztrans->sale($payload);
        } catch (ZtransUnknownOutcomeException $e) {
            $payment->addLog('ztrans_' . $operation . '_unknown_outcome', ['error' => $e->getMessage()]);

            return $this->keepForRetry(
                $payment,
                'The payment outcome is unconfirmed. Finalize this same payment again to confirm it; do not start a new payment.'
            );
        } catch (ZtransException $e) {
            return $this->failChallenge(
                $payment,
                'ztrans_' . $operation . '_error',
                $e->getMessage(),
                'Payment could not be processed.'
            );
        }

        if (! $response->isApproved()) {
            return $this->failChallenge(
                $payment,
                'ztrans_' . $operation . '_declined',
                $response->description,
                ZtransMessage::forCustomer($response),
                $this->responseLog($response)
            );
        }

        return $this->recordApproval($payment, $response, $useHold);
    }

    private function recordApproval(Payments $payment, ZtransResponse $response, bool $useHold): ThreeDSResult
    {
        $payment->payment_intent_id = $response->reference;
        $payment->authorization_code = $response->approvalCode;

        $metadata = ['data' => [
            self::META_REFERENCE => $response->reference,
            'ztrans_approval_code' => $response->approvalCode,
        ]];

        if ($useHold) {
            $payment->status = PaymentStatusEnum::AUTHORIZED->value;
            $payment->addMetadata($metadata);
            $payment->save();
        } else {
            $payment->markAsPaid($metadata);
        }

        $payment->addLog('ztrans_approved', $this->responseLog($response));

        return new ThreeDSResult(
            success: true,
            message: $response->description,
            status: $payment->status,
            data: ['reference' => $response->reference],
            raw: $response->body,
        );
    }

    private function requireChallenge(Payments $payment, array $authentication): ThreeDSResult
    {
        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->save();
        $payment->addLog('ztrans_challenge_required', ['three_ds_server_trans_id' => $authentication['threeDSServerTransID']]);

        $challengeUrl = $authentication['browserChallengeUrl'] ?? null;
        $challengeToken = $authentication['browserChallengeToken'] ?? null;

        return new ThreeDSResult(
            success: true,
            message: '3DS challenge required. Show the challenge, then call finalizePaymentChallenge with the card details again.',
            status: PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            data: [
                'challenge_url' => $challengeUrl,
                'challenge_token' => $challengeToken,
                'challenge_form' => $challengeUrl && $challengeToken
                    ? AutoSubmitChallengeForm::render($challengeUrl, ['browserChallengeToken' => $challengeToken])
                    : null,
                'three_ds_server_trans_id' => $authentication['threeDSServerTransID'],
            ],
            raw: $authentication,
        );
    }

    private function keepForRetry(Payments $payment, string $message): ThreeDSResult
    {
        return new ThreeDSResult(success: false, message: $message, status: (string) $payment->status);
    }

    private function failChallenge(
        Payments $payment,
        string $event,
        string $internalReason,
        string $customerMessage,
        array $context = []
    ): ThreeDSResult {
        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->addMetadata(['data' => [self::META_ERROR => $internalReason]]);
        $payment->save();
        $payment->addLog($event, [...$context, 'reason' => $internalReason]);

        return new ThreeDSResult(
            success: false,
            message: $customerMessage,
            status: PaymentStatusEnum::FAILED->value,
        );
    }

    private function buildAuthenticationPayload(
        Payments $payment,
        Order $order,
        array $card,
        array $context,
        string $integratorTxId,
        string $purchaseAmount
    ): array {
        $browser = $context['browser_info'] ?? [];
        $request = request();
        $holder = trim(($card['firstname'] ?? '') . ' ' . ($card['lastname'] ?? ''));

        return array_filter([
            'integratorTxId' => $integratorTxId,
            'urlRedirect' => $this->redirectReceiver->redirectUrl($order, $payment),
            'rd' => base64_encode('payment_id=' . $payment->id . '&integratorTxId=' . $integratorTxId),
            'acctNumber' => $card['number'],
            'cardExpiryDate' => $this->threeDSExpiration($card['expiration_date']),
            'purchaseAmount' => $purchaseAmount,
            'purchaseCurrency' => $this->currencyCode($order),
            'purchaseExponent' => '2',
            'purchaseDate' => now()->format('YmdHis'),
            'deviceChannel' => '02',
            'messageCategory' => '01',
            'browserAcceptHeader' => $browser['accept_header'] ?? 'text/plain',
            'browserIP' => $browser['ip_address'] ?? IPInfo::getClientIp($request),
            'browserJavaEnabled' => false,
            'browserJavascriptEnabled' => (bool) ($browser['javascript_enabled'] ?? true),
            'browserLanguage' => $browser['language'] ?? 'es',
            'browserScreenHeight' => (string) ($browser['screen_height'] ?? '1080'),
            'browserScreenWidth' => (string) ($browser['screen_width'] ?? '1920'),
            'browserTZ' => (string) ($browser['time_zone'] ?? '0'),
            'browserUserAgent' => $browser['user_agent'] ?? $request->userAgent() ?? '',
            'cardholderName' => $holder !== '' ? $holder : null,
            'email' => $order->user_email ?: null,
            'mobilePhone/cc' => ! empty($card['phone']) ? '1' : null,
            'mobilePhone/subscriber' => $card['phone'] ?? null,
            'billAddrLine1' => $card['address'] ?? null,
            'billAddrPostCode' => $card['zip_code'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function threeDSFields(Payments $payment, array $authentication): array
    {
        return [
            'tds_mode' => (int) $this->setting(ConfigurationEnum::THREE_DS_MODE, '2'),
            'tds_servertransactionid' => (string) $payment->getMetadata(self::META_SERVER_TRANS_ID),
            'tds_eci' => (string) ($authentication['eci'] ?? ''),
            'tds_avv' => (string) ($authentication['authenticationValue'] ?? ''),
            'tds_programprotocol' => (string) ($authentication['messageVersion'] ?? ''),
            'tds_dstransactionid' => (string) ($authentication['dsTransID'] ?? ''),
            'tds_status' => (string) ($authentication['transStatus'] ?? ''),
        ];
    }

    private function idempotencyKey(Payments $payment, string $operation): string
    {
        $keys = $payment->getMetadata(self::META_IDEMPOTENCY_KEYS) ?? [];

        if (! empty($keys[$operation])) {
            return $keys[$operation];
        }

        $key = $this->ztrans->createIdempotencyKey();

        $payment->addMetadata(['data' => [self::META_IDEMPOTENCY_KEYS => [...$keys, $operation => $key]]]);
        $payment->save();

        return $key;
    }

    private function requireCard(array $context): array
    {
        return $this->extractCard($context)
            ?? throw new ValidationException('Card number, expiration date and CVV are required for CardNet Ztrans.');
    }

    private function extractCard(array $context): ?array
    {
        $card = $context['card'] ?? [];

        foreach (['number', 'expiration_date', 'cvv'] as $field) {
            if (empty($card[$field])) {
                return null;
            }
        }

        return [...$card, 'number' => preg_replace('/\s+/', '', (string) $card['number'])];
    }

    private function resolveUseHold(Payments $payment, array $context): bool
    {
        if (array_key_exists('use_hold', $context)) {
            return (bool) $context['use_hold'];
        }

        if (isset($payment->metadata['use_hold'])) {
            return (bool) $payment->metadata['use_hold'];
        }

        return filter_var($this->setting(ConfigurationEnum::USE_HOLD, '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function assertSupportedCurrency(Order $order): void
    {
        $this->currencyCode($order);
    }

    private function currencyCode(Order $order): string
    {
        $currency = CurrencyEnum::tryFrom(strtoupper(trim((string) $order->currency)));

        if ($currency === null) {
            throw new ValidationException('CardNet Ztrans supports DOP and USD; order ' . $order->getId() . ' has currency ' . $order->currency);
        }

        return $currency->isoNumericCode();
    }

    private function isSettled(Payments $payment): bool
    {
        return in_array($payment->status, array_map(fn (PaymentStatusEnum $status): string => $status->value, self::TERMINAL_STATUSES), true);
    }

    private function referenceNumber(Order $order): string
    {
        return (string) $order->order_number;
    }

    private function clientIp(Order $order): string
    {
        return (string) ($order->ip_address ?: IPInfo::getClientIp(request()));
    }

    private function responseLog(ZtransResponse $response): array
    {
        return [
            'response_code' => $response->responseCode,
            'internal_response_code' => $response->internalResponseCode,
            'description' => $response->description,
            'source' => $response->source,
            'reference' => $response->reference,
        ];
    }
}
