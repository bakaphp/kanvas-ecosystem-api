<?php

declare(strict_types=1);

namespace Kanvas\Souk\Insurance\Contracts;

use Kanvas\Souk\Orders\Models\Order;

/**
 * Provider-agnostic contract for insurance connectors (Universal Seguros, and any
 * auto/travel/etc. insurance provider added later). Mirrors the shape of
 * Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface — the GraphQL mutations stay
 * fixed (insuranceCreateQuote, insuranceRequestPaymentLink, insuranceEmitPolicy) and route
 * to the right implementation via InsuranceProcessorFactory::make($provider, ...), instead
 * of growing a new set of `{provider}CreateQuote` mutations per provider.
 */
interface InsuranceProcessorInterface
{
    /**
     * Provider identifier — matches the `integrations.name` / IntegrationsEnum value
     * used to resolve this processor (e.g. "universal_seguros").
     */
    public function name(): string;

    /**
     * Request a quote for the given product and stamp the result onto the Order.
     *
     * @param array<string, mixed> $input
     */
    public function createQuote(Order $order, string $product, array $input): array;

    /**
     * Request (or email) a payment link for a previously quoted Order.
     */
    public function requestPaymentLink(Order $order, bool $byEmail = false): array;

    /**
     * Emit the policy for a previously quoted/paid Order.
     */
    public function emitPolicy(Order $order): array;
}
