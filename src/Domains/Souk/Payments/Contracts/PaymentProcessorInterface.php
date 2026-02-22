<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Models\Payments;

interface PaymentProcessorInterface
{
    /**
     * Processor identifier — matches the `processor` column on payment_methods_credentials.
     */
    public function name(): string;

    /**
     * Authorize (and optionally capture) a payment.
     * For synchronous processors (Azul) this is a combined auth+capture.
     * For async processors (EchoPay) this returns AUTHORIZED and a separate capture() call is expected.
     */
    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult;

    /**
     * Capture a previously authorized payment.
     * Processors that capture immediately in authorize() should return a no-op success here.
     */
    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult;

    /**
     * Refund a paid or captured payment, partially or fully.
     */
    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult;

    /**
     * Void an authorized (but not yet captured) payment.
     * Processors that do not support void should throw \DomainException.
     */
    public function void(Payments $payment, Order $order, array $context = []): VoidResult;

    /**
     * Verify the current status of a payment via the processor's inquiry endpoint.
     * Useful to reconcile payments when the original response was lost or ambiguous.
     * Processors that do not support verification should throw \DomainException.
     */
    public function verify(Payments $payment, Order $order): VerifyResult;
}
