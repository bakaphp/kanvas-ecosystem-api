<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\PayWay;

use DomainException;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\Contracts\PayWayClientInterface;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Connectors\PayWay\Enums\CustomFieldEnum;
use Kanvas\Connectors\PayWay\PayWayEncryptor;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Override;
use Throwable;

final class PayWayProcessor implements PaymentProcessorInterface, ThreeDSProcessorInterface
{
    // Status tags used in ThreeDSResult.status — FE can switch on these.
    private const string STATUS_STEP2_WAITING = 'payway_waiting_device_data';
    private const string STATUS_STEP4_OTP = 'payway_pending_otp';
    private const string STATUS_TERMINAL = 'payway_terminal';

    // Metadata keys written under Payment.metadata['data'] for PayWay flows.
    // Durable (survive terminal transitions): PAYWAY_NUMERO_UUID, PAYWAY_NUMERO_AUTORIZACION, PAYWAY_NUMERO_COMPRA.
    // Transient (cleared on terminal): PAYWAY_TOKEN_ACCESO, PAYWAY_URL_COLECCION, PAYWAY_SEGUIMIENTO.
    private const string META_TOKEN_ACCESO = 'payway_token_acceso';
    private const string META_URL_COLECCION = 'payway_url_coleccion_dato_dispositivo';
    private const string META_REFERENCIA_ID = 'payway_referencia_id';
    private const string META_NUMERO_TRANSACCION_SISTEMA = 'payway_numero_transaccion_sistema';
    private const string META_NUMERO_TRANSACCION_REFERENCIA = 'payway_numero_transaccion_referencia';
    private const string META_SEGUIMIENTO = 'payway_seguimiento';
    private const string META_NUMERO_UUID = 'payway_numero_uuid';
    private const string META_NUMERO_AUTORIZACION = 'payway_numero_autorizacion';
    private const string META_NUMERO_COMPRA = 'payway_numero_compra';
    private const string META_ERROR = 'payway_error';
    private const string META_OTP_TOKEN_ACCESO = 'payway_otp_token_acceso';
    private const string META_OTP_URL = 'payway_otp_url';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly PayWayClientInterface $client,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return 'payway';
    }

    #[Override]
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        throw new DomainException('PayWay requires 3DS; call startPaymentChallenge instead.');
    }

    #[Override]
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        if ($payment->status === PaymentStatusEnum::PAID->value) {
            return new CaptureResult(
                success: true,
                message: 'Already captured (PayWay captures on authorize)',
                transactionId: $payment->payment_intent_id ?? '',
            );
        }

        throw new DomainException(
            'PayWay does not support separate capture; payments are captured on authorize. Current Payment status: ' . $payment->status
        );
    }

    #[Override]
    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        throw new DomainException('PayWay does not support refunds — use voidPayment within the same business day.');
    }

    #[Override]
    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        if ($payment->status !== PaymentStatusEnum::PAID->value) {
            return new VoidResult(
                success: false,
                message: 'Can only void PAID payments.',
                transactionId: '',
            );
        }

        $numeroCompra = $payment->getMetadata(self::META_NUMERO_COMPRA);

        if (empty($numeroCompra)) {
            $payment->addLog('payway_void_failed', ['reason' => 'missing_numero_compra']);

            return new VoidResult(
                success: false,
                message: 'No PayWay transaction reference on payment.',
                transactionId: '',
            );
        }

        try {
            $traceId = Str::uuid()->toString();

            $payment->addLog('payway_void_initiated', [
                'numero_compra' => $numeroCompra,
                'trace_id' => $traceId,
            ]);

            $response = $this->client->voidTransaction($numeroCompra, $traceId);

            if ($response->isSuccess()) {
                $payment->status = PaymentStatusEnum::CANCELLED->value;
                $payment->save();

                $payment->addLog('payway_void_success', [
                    'numero_compra' => $numeroCompra,
                    'trace_id' => $traceId,
                ]);

                return new VoidResult(
                    success: true,
                    message: 'Void successful',
                    transactionId: $payment->payment_intent_id ?? '',
                );
            }

            $payment->addLog('payway_void_failed', [
                'numero_compra' => $numeroCompra,
                'trace_id' => $traceId,
                'return_code' => $response->returnCode,
                'message' => $response->message,
            ]);

            return new VoidResult(
                success: false,
                message: $response->message,
                transactionId: '',
            );
        } catch (Throwable $e) {
            $payment->addLog('payway_void_unexpected_failure', ['error' => $e->getMessage()]);

            return new VoidResult(
                success: false,
                message: 'PayWay void failed: ' . $e->getMessage(),
                transactionId: '',
            );
        }
    }

    #[Override]
    public function verify(Payments $payment, Order $order): VerifyResult
    {
        $numeroCompra = $payment->getMetadata(self::META_NUMERO_COMPRA);

        if (empty($numeroCompra)) {
            return new VerifyResult(
                success: false,
                message: 'No PayWay transaction reference on payment.',
                transactionId: '',
                isoCode: '',
                responseCode: '',
            );
        }

        try {
            $response = $this->client->getTransaction($numeroCompra);

            $payment->addLog('payway_verify', [
                'numero_compra' => $numeroCompra,
                'return_code' => $response->returnCode,
                'message' => $response->message,
            ]);

            return new VerifyResult(
                success: $response->isSuccess(),
                message: $response->message,
                transactionId: (string) $response->get('numeroPayWay', $numeroCompra),
                isoCode: '',
                responseCode: $response->returnCode,
                raw: $response->payload,
            );
        } catch (Throwable $e) {
            $payment->addLog('payway_verify_unexpected_failure', ['error' => $e->getMessage()]);

            return new VerifyResult(
                success: false,
                message: 'PayWay verify failed: ' . $e->getMessage(),
                transactionId: '',
                isoCode: '',
                responseCode: '',
            );
        }
    }

    /**
     * Step 1 of the PayWay 3DS flow: POST /payments/using3ds.
     *
     * Raw-card path: encrypts tarjetaNumero + tarjetaCvv2 from $context['card'].
     * Saved-card path: sends tarjetaUuid from the PaymentMethod metadata when $context['card'] is absent.
     *
     * Outcomes:
     *  - returnCode 01 → Payment → WAITING_DEVICE_DATA; iframe details returned in ThreeDSResult.data.
     *  - returnCode 00 → rare no-3DS path; Payment → PAID immediately.
     *  - any other code → Payment → FAILED; payway_error stored in metadata.
     */
    #[Override]
    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        try {
            $payload = $this->buildStep1Payload($payment, $order, $context);
            $response = $this->client->payUsing3ds($payload);

            return match (true) {
                $response->is3dsStepRequired() => $this->handleStep2Required($payment, $response),
                $response->isSuccess()         => $this->handleStep1NoChallenge($payment, $response),
                default                        => $this->handleStep1Failure($payment, $response),
            };
        } catch (Throwable $e) {
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->saveQuietly();
            $payment->addLog('payway_step1_unexpected_failure', ['error' => $e->getMessage()]);

            return new ThreeDSResult(
                success: false,
                message: 'PayWay startChallenge failed: ' . $e->getMessage(),
                status: self::STATUS_TERMINAL,
            );
        }
    }

    /**
     * Step 3 of the PayWay 3DS flow, and optionally Step 4 (OTP re-entry).
     *
     * Re-entrant: callers MUST be prepared to invoke this method MORE THAN ONCE per
     * Payment. The first call runs Step 3 after the FE's device-data iframe completes;
     * if PayWay then requires an OTP challenge (paso=4, returnCode 01), the Payment
     * transitions to PENDING_AUTHORIZATION and the FE re-invokes this method after the
     * user submits the OTP. Pass $context['otp_terminal_status'] = 'PAID'|'FAILED' on
     * the second call to map the FE-supplied terminal state into the Payment.
     *
     * Idempotent: invoking on a Payment already in PAID/FAILED/CANCELLED returns a
     * ThreeDSResult reflecting the current state without contacting PayWay.
     */
    #[Override]
    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        if ($this->isTerminalStatus($payment->status)) {
            $payment->addLog('payway_finalize_idempotent_skip', [
                'current_status' => $payment->status,
            ]);

            return new ThreeDSResult(
                success: $payment->status === PaymentStatusEnum::PAID->value,
                message: 'Payment already in terminal state: ' . $payment->status,
                status: self::STATUS_TERMINAL,
                data: [
                    self::META_NUMERO_UUID => $payment->getMetadata(self::META_NUMERO_UUID),
                    self::META_NUMERO_COMPRA => $payment->getMetadata(self::META_NUMERO_COMPRA),
                ],
            );
        }

        // Second call after OTP: FE signals the terminal state via context.
        if (isset($context['otp_terminal_status'])) {
            return $this->handleOtpTerminalResult($payment, $context['otp_terminal_status']);
        }

        // PENDING_AUTHORIZATION re-entry without OTP terminal status is a no-op —
        // protects against the FE accidentally re-calling finalizeChallenge before
        // the user submits the OTP. PayWay's seguimientoDatos3ds at this stage is
        // for the OTP iframe, not for re-sending to Step 3.
        if ($payment->status === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
            $payment->addLog('payway_finalize_otp_pending_noop', [
                'current_status' => $payment->status,
            ]);

            return new ThreeDSResult(
                success: false,
                message: 'OTP still pending; awaiting otp_terminal_status from FE.',
                status: self::STATUS_STEP4_OTP,
                data: [
                    self::META_OTP_TOKEN_ACCESO => $payment->getMetadata(self::META_OTP_TOKEN_ACCESO),
                    self::META_OTP_URL => $payment->getMetadata(self::META_OTP_URL),
                ],
            );
        }

        try {
            $seguimiento = $payment->getMetadata(self::META_SEGUIMIENTO);
            $payload = array_merge(
                ['seguimientoDatos3ds' => $seguimiento],
                $this->buildBasePayload($payment, $order),
            );

            $response = $this->client->completePayment($payload);

            // Escenario 2: PayWay requires OTP (paso=4, returnCode=01).
            if ($response->is3dsStepRequired() && $response->get('paso') === 4) {
                return $this->handleOtpRequired($payment, $response);
            }

            return $this->handleStep3Terminal($payment, $response);
        } catch (Throwable $e) {
            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->saveQuietly();
            $payment->addLog('payway_step3_unexpected_failure', ['error' => $e->getMessage()]);

            return new ThreeDSResult(
                success: false,
                message: 'PayWay finalizeChallenge failed: ' . $e->getMessage(),
                status: self::STATUS_TERMINAL,
            );
        }
    }

    private function handleStep2Required(Payments $payment, PayWayResponse $response): ThreeDSResult
    {
        $tokenAcceso = $response->get('tokenAcceso', '');
        $urlColeccion = $response->get('urlColeccionDatoDispositivo', '');
        $referenciaId = $response->get('referenciaId', '');
        $numeroTransaccionSistema = $response->get('numeroTransaccionSistema', '');
        $numeroTransaccionReferencia = $response->get('numeroTransaccionReferencia', '');

        $payment->addMetadata([
            'data' => [
                self::META_TOKEN_ACCESO => $tokenAcceso,
                self::META_URL_COLECCION => $urlColeccion,
                self::META_REFERENCIA_ID => $referenciaId,
                self::META_NUMERO_TRANSACCION_SISTEMA => $numeroTransaccionSistema,
                self::META_NUMERO_TRANSACCION_REFERENCIA => $numeroTransaccionReferencia,
                self::META_SEGUIMIENTO => $response->payload,
            ],
        ]);

        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
        $payment->save();

        $payment->addLog('payway_step1_success_3ds_required', [
            'referencia_id' => $referenciaId,
            'numero_transaccion_sistema' => $numeroTransaccionSistema,
        ]);

        $data = [
            'tokenAcceso' => $tokenAcceso,
            'urlColeccionDatoDispositivo' => $urlColeccion,
            'referenciaId' => $referenciaId,
            'numeroTransaccionSistema' => $numeroTransaccionSistema,
            'numeroTransaccionReferencia' => $numeroTransaccionReferencia,
        ];

        return new ThreeDSResult(
            success: true,
            message: 'PayWay Step 2 device data collection required',
            status: self::STATUS_STEP2_WAITING,
            data: $data,
            raw: $response->payload,
        );
    }

    private function handleStep1NoChallenge(Payments $payment, PayWayResponse $response): ThreeDSResult
    {
        $numeroUuid = $response->get('numeroUuid', '');
        $numeroCompra = $response->get('numeroCompra', '');
        $numeroAutorizacion = $response->get('numeroAutorizacion', '');

        $payment->addMetadata([
            'data' => [
                self::META_NUMERO_UUID => $numeroUuid,
                self::META_NUMERO_COMPRA => $numeroCompra,
                self::META_NUMERO_AUTORIZACION => $numeroAutorizacion,
            ],
        ]);

        $payment->processor = $this->name();
        $payment->markAsPaid();

        $this->persistUuidToPaymentMethod($payment, $numeroUuid);

        $payment->addLog('payway_step1_success_no_3ds', [
            'numero_uuid' => $numeroUuid,
            'numero_compra' => $numeroCompra,
        ]);

        return new ThreeDSResult(
            success: true,
            message: 'PayWay approved without 3DS challenge',
            status: self::STATUS_TERMINAL,
            data: [
                self::META_NUMERO_UUID => $numeroUuid,
                self::META_NUMERO_COMPRA => $numeroCompra,
                self::META_NUMERO_AUTORIZACION => $numeroAutorizacion,
            ],
            raw: $response->payload,
        );
    }

    private function handleStep1Failure(Payments $payment, PayWayResponse $response): ThreeDSResult
    {
        $errorMessage = $response->message ?: 'PayWay declined (returnCode=' . $response->returnCode . ')';

        $payment->addMetadata([
            'data' => [
                self::META_ERROR => $errorMessage,
            ],
        ]);

        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->save();

        $payment->addLog('payway_step1_failed', [
            'return_code' => $response->returnCode,
            'message' => $errorMessage,
        ]);

        return new ThreeDSResult(
            success: false,
            message: $errorMessage,
            status: self::STATUS_TERMINAL,
            raw: $response->payload,
        );
    }

    private function handleStep3Terminal(Payments $payment, PayWayResponse $response): ThreeDSResult
    {
        if ($response->isSuccess()) {
            $numeroUuid = $response->get('numeroUuid', '');
            $numeroCompra = $response->get('numeroCompra', '');
            $numeroAutorizacion = $response->get('numeroAutorizacion', '');

            $payment->addMetadata([
                'data' => [
                    self::META_NUMERO_UUID => $numeroUuid,
                    self::META_NUMERO_COMPRA => $numeroCompra,
                    self::META_NUMERO_AUTORIZACION => $numeroAutorizacion,
                ],
            ]);

            $this->clearTransientMetadata($payment);

            $payment->processor = $this->name();
            $payment->markAsPaid();

            $this->persistUuidToPaymentMethod($payment, $numeroUuid);

            $payment->addLog('payway_step3_terminal_paid', [
                'numero_uuid' => $numeroUuid,
                'numero_compra' => $numeroCompra,
            ]);

            return new ThreeDSResult(
                success: true,
                message: 'PayWay payment completed successfully',
                status: self::STATUS_TERMINAL,
                data: [
                    self::META_NUMERO_UUID => $numeroUuid,
                    self::META_NUMERO_COMPRA => $numeroCompra,
                    self::META_NUMERO_AUTORIZACION => $numeroAutorizacion,
                ],
                raw: $response->payload,
            );
        }

        $errorMessage = $response->message ?: 'PayWay Step 3 declined (returnCode=' . $response->returnCode . ')';

        $payment->addMetadata([
            'data' => [
                self::META_ERROR => $errorMessage,
            ],
        ]);

        $this->clearTransientMetadata($payment);

        $payment->status = PaymentStatusEnum::FAILED->value;
        $payment->save();

        $payment->addLog('payway_step3_terminal_failed', [
            'return_code' => $response->returnCode,
            'message' => $errorMessage,
        ]);

        return new ThreeDSResult(
            success: false,
            message: $errorMessage,
            status: self::STATUS_TERMINAL,
            raw: $response->payload,
        );
    }

    private function handleOtpRequired(Payments $payment, PayWayResponse $response): ThreeDSResult
    {
        $otpTokenAcceso = $response->get('tokenAcceso', '');
        $otpUrl = $response->get('urlColeccionDatoDispositivo', '');

        $payment->addMetadata([
            'data' => [
                self::META_OTP_TOKEN_ACCESO => $otpTokenAcceso,
                self::META_OTP_URL => $otpUrl,
                self::META_SEGUIMIENTO => $response->payload,
            ],
        ]);

        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->save();

        $payment->addLog('payway_step3_otp_required', [
            'otp_url' => $otpUrl,
        ]);

        return new ThreeDSResult(
            success: true,
            message: 'PayWay OTP challenge required (paso=4)',
            status: self::STATUS_STEP4_OTP,
            data: [
                'tokenAcceso' => $otpTokenAcceso,
                'urlColeccionDatoDispositivo' => $otpUrl,
                'paso' => 4,
            ],
            raw: $response->payload,
        );
    }

    private function handleOtpTerminalResult(Payments $payment, string $otpStatus): ThreeDSResult
    {
        $isPaid = strtoupper($otpStatus) === 'PAID';

        $this->clearTransientMetadata($payment);

        $payment->status = $isPaid ? PaymentStatusEnum::PAID->value : PaymentStatusEnum::FAILED->value;
        $payment->save();

        $payment->addLog('payway_finalize_otp_submitted', [
            'otp_terminal_status' => $otpStatus,
            'mapped_status' => $payment->status,
        ]);

        return new ThreeDSResult(
            success: $isPaid,
            message: $isPaid ? 'PayWay OTP challenge completed — payment paid' : 'PayWay OTP challenge failed',
            status: self::STATUS_TERMINAL,
            data: [
                self::META_NUMERO_UUID => $payment->getMetadata(self::META_NUMERO_UUID),
                self::META_NUMERO_COMPRA => $payment->getMetadata(self::META_NUMERO_COMPRA),
            ],
        );
    }

    private function buildStep1Payload(Payments $payment, Order $order, array $context): array
    {
        $encryptionKey = (string) $this->company->get(ConfigurationEnum::PAYWAY_ENCRYPTION_KEY->value);
        $base = $this->buildBasePayload($payment, $order);

        $paymentMethod = $payment->paymentMethod;
        $savedUuid = $paymentMethod?->getMetadata(CustomFieldEnum::PAYWAY_NUMERO_UUID->value);

        if ($savedUuid) {
            $base['tarjetaUuid'] = $savedUuid;
        } else {
            $card = $context['card'] ?? [];
            $base['tarjetaNumero'] = PayWayEncryptor::encrypt(
                preg_replace('/\s+/', '', (string) ($card['number'] ?? '')),
                $encryptionKey,
            );
            $base['tarjetaCvv2'] = PayWayEncryptor::encrypt(
                (string) ($card['cvv'] ?? $card['cvc'] ?? ''),
                $encryptionKey,
            );
            $base['tarjetaVencimiento'] = $this->normalizeExpiration(
                (string) ($card['expiration_date'] ?? $card['expiration'] ?? '')
            );
        }

        $base['datos3ds'] = $this->buildDatos3ds($payment, $order, $context);

        return $base;
    }

    private function buildBasePayload(Payments $payment, Order $order): array
    {
        $clienteIp = $order->ip_address ?? '127.0.0.1';

        $user = $payment->paymentMethod?->users_id
            ? $payment->paymentMethod->user
            : null;
        $clienteUsuario = $user?->email ?? $order->user_email ?? '';

        return [
            'monto' => number_format((float) $order->getTotalAmount(), 2, '.', ''),
            'traceId' => (string) $payment->idempotency_key,
            'clienteIp' => $clienteIp,
            'clienteUsuario' => $clienteUsuario,
            'numeroOrden' => (string) $order->order_number,
        ];
    }

    private function buildDatos3ds(Payments $payment, Order $order, array $context): array
    {
        $card = $context['card'] ?? [];
        $meta = $payment->paymentMethod?->metadata ?? [];

        return [
            'nombre' => ($card['firstname'] ?? $meta['firstname'] ?? ''),
            'apellido' => ($card['lastname'] ?? $meta['lastname'] ?? ''),
            'direccion' => ($card['address'] ?? $meta['address'] ?? ''),
            'telefono' => ($card['phone'] ?? $meta['phone'] ?? ''),
            'pais' => ($card['country'] ?? $meta['country'] ?? 'SV'),
            'codigoPostal' => ($card['zip'] ?? $card['zip_code'] ?? $meta['zip_code'] ?? ''),
            'email' => ($card['email'] ?? $meta['email'] ?? $order->user_email ?? ''),
        ];
    }

    private function persistUuidToPaymentMethod(Payments $payment, string $numeroUuid): void
    {
        if (empty($numeroUuid) || ! $payment->paymentMethod) {
            return;
        }

        $paymentMethod = $payment->paymentMethod;
        $paymentMethod->metadata = array_merge(
            $paymentMethod->metadata ?? [],
            [CustomFieldEnum::PAYWAY_NUMERO_UUID->value => $numeroUuid]
        );
        $paymentMethod->save();
    }

    private function clearTransientMetadata(Payments $payment): void
    {
        $currentData = $payment->metadata['data'] ?? [];

        unset(
            $currentData[self::META_TOKEN_ACCESO],
            $currentData[self::META_URL_COLECCION],
            $currentData[self::META_SEGUIMIENTO],
            $currentData[self::META_OTP_TOKEN_ACCESO],
            $currentData[self::META_OTP_URL],
        );

        $payment->metadata = array_merge(
            $payment->metadata ?? [],
            ['data' => $currentData],
        );
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            PaymentStatusEnum::PAID->value,
            PaymentStatusEnum::FAILED->value,
            PaymentStatusEnum::CANCELLED->value,
        ], true);
    }

    private function normalizeExpiration(string $expiration): string
    {
        $clean = str_replace(['/', '-', ' '], '', $expiration);

        if (strlen($clean) === 4) {
            // MMYY → YYYYMM
            return '20' . substr($clean, 2, 2) . substr($clean, 0, 2);
        }

        if (strlen($clean) === 10) {
            // YYYY-MM-DD → YYYYMM
            return substr($clean, 0, 4) . substr($clean, 4, 2);
        }

        // Assume already YYYYMM or passthrough
        return $clean;
    }
}
