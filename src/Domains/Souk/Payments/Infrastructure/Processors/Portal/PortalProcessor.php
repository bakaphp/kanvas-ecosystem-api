<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Infrastructure\Processors\Portal;

use Baka\Users\Contracts\UserInterface;
use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\ThreeDSResult;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Kanvas\Souk\Payments\DataTransferObject\VerifyResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Models\Payments;

/**
 * Portal / EchoPay payment processor (Dominican Republic).
 *
 * New, interface-based replacement for the legacy PortalPaymentProcessor under
 * Kanvas\Souk\Payments\Providers. The legacy class continues to back existing
 * production endpoints and is not affected by this processor.
 *
 * Capabilities:
 *   - PaymentProcessorInterface      : authorize / capture / refund / void / verify
 *   - TokenizationProcessorInterface : tokenize / deleteToken (delegates to EchoPayService)
 *   - ThreeDSProcessorInterface      : startChallenge / finalizeChallenge
 *
 * Flow:
 *   1. tokenize()          — store card in EchoPay vault, return reusable token.
 *   2. startChallenge()    — setupPayer + checkPayerEnrollment; returns device-data
 *                            collection URL or proceeds straight to authorize when ECI
 *                            indicates frictionless authentication.
 *   3. finalizeChallenge() — validatePayerAuthResult after the browser step; on success
 *                            authorizePayment is called to charge.
 *   4. authorize()         — entry point that gates straight-through (non-3DS) callers;
 *                            EchoPay always requires 3DS so this throws and points the
 *                            caller at startChallenge().
 *   5. capture() / refund() / void() / verify() — post-authorization operations.
 */
final class PortalProcessor implements PaymentProcessorInterface, TokenizationProcessorInterface, ThreeDSProcessorInterface
{
    protected EchoPayService $service;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        ?EchoPayService $service = null,
    ) {
        $this->service = $service ?? new EchoPayService($app, $company);
    }

    public function name(): string
    {
        return 'portal';
    }

    // -------------------------------------------------------------------------
    // PaymentProcessorInterface
    // -------------------------------------------------------------------------

    public function authorize(Payments $payment, Order $order, array $context = []): AuthorizeResult
    {
        throw new DomainException('PortalProcessor requires 3DS — call startChallenge() instead of authorize().');
    }

    public function capture(Payments $payment, Order $order, ?float $amount = null, array $context = []): CaptureResult
    {
        throw new DomainException('PortalProcessor::capture() not implemented yet.');
    }

    public function refund(Payments $payment, Order $order, ?float $amount = null, array $context = []): RefundResult
    {
        throw new DomainException('PortalProcessor::refund() not implemented yet.');
    }

    public function void(Payments $payment, Order $order, array $context = []): VoidResult
    {
        throw new DomainException('PortalProcessor::void() not implemented yet.');
    }

    public function verify(Payments $payment, Order $order): VerifyResult
    {
        throw new DomainException('PortalProcessor::verify() not implemented yet.');
    }

    // -------------------------------------------------------------------------
    // TokenizationProcessorInterface
    // -------------------------------------------------------------------------

    public function tokenize(array $cardDetails, UserInterface $user): TokenizeResult
    {
        throw new DomainException('PortalProcessor::tokenize() not wired yet — see Phase 2.');
    }

    public function deleteToken(string $token): bool
    {
        throw new DomainException('PortalProcessor::deleteToken() not wired yet — see Phase 2.');
    }

    // -------------------------------------------------------------------------
    // ThreeDSProcessorInterface
    // -------------------------------------------------------------------------

    public function startChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        throw new DomainException('PortalProcessor::startChallenge() not implemented yet — see Phase 3.');
    }

    public function finalizeChallenge(Payments $payment, Order $order, array $context = []): ThreeDSResult
    {
        throw new DomainException('PortalProcessor::finalizeChallenge() not implemented yet — see Phase 4.');
    }
}
