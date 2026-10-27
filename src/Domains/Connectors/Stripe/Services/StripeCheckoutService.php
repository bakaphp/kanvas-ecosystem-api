<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Illuminate\Validation\ValidationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Exceptions\ValidationException as KanvasValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutService
{
    private ?StripeClient $stripeOverride;

    public function __construct(
        protected AppInterface $app,
        protected ?Companies $company = null,
        ?StripeClient $stripe = null
    ) {
        $this->stripeOverride = $stripe;
    }

    private function stripe(): StripeClient
    {
        return $this->stripeOverride ?? StripeAccount::resolve($this->app, $this->company)->client();
    }

    /**
     * Create a Stripe Checkout Session pinned to a tax snapshot.
     *
     * - Amount comes from snapshot->gateway_due (checkout_payload.totals.gateway_due)
     * - Reuses the existing session when the snapshot hasn't changed (stripe_session_id present and status != expired)
     * - Rejects automatic_tax: Souk owns tax, not Stripe Tax
     * - Shipping collection is disabled: Souk owns shipping
     * - Requires a Stripe destination (transfer_data) to be configured
     */
    public function createSessionFromSnapshot(
        OrderTaxSnapshot $snapshot,
        string $successUrl,
        string $cancelUrl,
        array $options = []
    ): Session {
        if (isset($options['automatic_tax']) && $options['automatic_tax'] === true) {
            throw ValidationException::withMessages(['automatic_tax' => 'automatic_tax_not_allowed_with_souk_tax']);
        }

        $destination = $this->app->get(ConfigurationEnum::STRIPE_ACCOUNT_CONNECTED->value);

        if (empty($destination) && (bool) $this->app->get('STRIPE_SESSION_REQUIRE_DESTINATION')) {
            throw ValidationException::withMessages(['stripe_account' => 'stripe_destination_account_required']);
        }

        if ($snapshot->stripe_session_id) {
            try {
                $existing = $this->stripe()->checkout->sessions->retrieve($snapshot->stripe_session_id);

                if ($existing->status !== 'expired') {
                    return $existing;
                }
            } catch (Exception) {
            }
        }

        $gatewayDue = (float) ($snapshot->checkout_payload['totals']['gateway_due'] ?? 0.0);
        $currency = strtolower($options['currency'] ?? 'usd');

        $sessionData = [
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $options['product_name'] ?? "Order #{$snapshot->merchant_reference}",
                        ],
                        'unit_amount' => $this->convertToStripeAmount($gatewayDue, $currency),
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'shipping_address_collection' => null,
            'metadata' => [
                'snapshot_uuid' => $snapshot->uuid,
                'apps_id' => $snapshot->apps_id,
            ],
        ];

        if (isset($options['customer_email'])) {
            $sessionData['customer_email'] = $options['customer_email'];
        }

        $session = $this->stripe()->checkout->sessions->create($sessionData);

        $snapshot->stripe_session_id = $session->id;
        $snapshot->save();

        return $session;
    }

    /**
     * Expire all open Stripe Checkout Sessions tied to a snapshot.
     * Called when a snapshot transitions to superseded.
     */
    public function expireSessionsForSnapshot(OrderTaxSnapshot $snapshot): void
    {
        if (! $snapshot->stripe_session_id) {
            return;
        }

        try {
            $existing = $this->stripe()->checkout->sessions->retrieve($snapshot->stripe_session_id);

            if ($existing->status === 'open') {
                $this->stripe()->checkout->sessions->expire($snapshot->stripe_session_id);
            }
        } catch (Exception) {
        }
    }

    public function createCheckoutSession(Order $order, array $options = []): Session
    {
        $this->validateOrder($order);

        $sessionData = [
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($order->currency ?? 'usd'),
                        'product_data' => [
                            'name' => "Order #{$order->getOrderNumber()}",
                            'description' => $this->getOrderDescription($order),
                        ],
                        'unit_amount' => $this->convertToStripeAmount($order->total_net_amount, $order->currency),
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => $options['success_url'] ?? $this->getDefaultSuccessUrl($order),
            'cancel_url' => $options['cancel_url'] ?? $this->getDefaultCancelUrl($order),
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->getOrderNumber(),
                'app_id' => $order->apps_id,
                'company_id' => $order->companies_id,
            ],
        ];

        if ($order->people_id && $order->people && $email = $order->getEmail()) {
            $sessionData['customer_email'] = $email;
        }

        $session = $this->stripe()->checkout->sessions->create($sessionData);

        $order->addMetadata('stripe_session_id', $session->id);
        $order->addMetadata('stripe_checkout_url', $session->url);

        return $session;
    }

    public function createDetailedCheckoutSession(Order $order, array $options = []): Session
    {
        $this->validateOrder($order);

        $lineItems = [];

        foreach ($order->items as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => strtolower($item->currency ?? 'usd'),
                    'product_data' => [
                        'name' => $item->product_name,
                        'description' => "SKU: {$item->product_sku}",
                    ],
                    'unit_amount' => $this->convertToStripeAmount($item->unit_price_gross_amount, $item->currency),
                ],
                'quantity' => $item->quantity,
            ];
        }

        if ($order->shipping_price_gross_amount > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => strtolower($order->currency ?? 'usd'),
                    'product_data' => [
                        'name' => $order->shipping_method_name ?? 'Shipping',
                    ],
                    'unit_amount' => $this->convertToStripeAmount($order->shipping_price_gross_amount, $order->currency),
                ],
                'quantity' => 1,
            ];
        }

        $sessionData = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $options['success_url'] ?? $this->getDefaultSuccessUrl($order),
            'cancel_url' => $options['cancel_url'] ?? $this->getDefaultCancelUrl($order),
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->getOrderNumber(),
                'app_id' => $order->apps_id,
                'company_id' => $order->companies_id,
            ],
        ];

        if ($order->people_id && $order->people && $email = $order->getEmail()) {
            $sessionData['customer_email'] = $email;
        }

        $session = $this->stripe()->checkout->sessions->create($sessionData);

        $order->addMetadata('stripe_session_id', $session->id);
        $order->addMetadata('stripe_checkout_url', $session->url);

        return $session;
    }

    protected function getOrderDescription(Order $order): string
    {
        $itemCount = $order->items->count();
        $itemText = $itemCount === 1 ? 'item' : 'items';

        return "Order containing {$itemCount} {$itemText}";
    }

    protected function convertToStripeAmount(float $amount, ?string $currency = 'USD'): int
    {
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'clp'];
        $currency = strtolower($currency ?? 'usd');

        return in_array($currency, $zeroDecimalCurrencies)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    protected function validateOrder(Order $order): void
    {
        if ($order->total_gross_amount <= 0) {
            throw new KanvasValidationException('Order total must be greater than 0');
        }
    }

    protected function getDefaultSuccessUrl(Order $order): string
    {
        return $this->app->get(ConfigurationEnum::CHECKOUT_SUCCESS_URL->value) . "/orders/{$order->id}/success?session_id={CHECKOUT_SESSION_ID}";
    }

    protected function getDefaultCancelUrl(Order $order): string
    {
        return $this->app->get(ConfigurationEnum::CHECKOUT_CANCEL_URL->value) . "/orders/{$order->id}/cancel?session_id={CHECKOUT_SESSION_ID}";
    }

    public function getCheckoutSessionByOrder(Order $order): ?Session
    {
        $sessionId = $order->getMetadata('stripe_session_id');

        if (! $sessionId) {
            return null;
        }

        try {
            return $this->stripe()->checkout->sessions->retrieve($sessionId);
        } catch (Exception $e) {
            return null;
        }
    }
}
