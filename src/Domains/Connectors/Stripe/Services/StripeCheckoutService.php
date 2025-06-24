<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Stripe\StripeClient;

class StripeCheckoutService
{
    protected StripeClient $stripe;

    public function __construct(
        protected AppInterface $app,
    ) {
        $this->stripe = new StripeClient($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
    }

    /**
     * Create a simple checkout session for the total order amount
     */
    public function createCheckoutSession(Order $order, array $options = []): \Stripe\Checkout\Session
    {
        $this->validateOrder($order);

        // Create a single line item for the entire order
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
                        'unit_amount' => $this->convertToStripeAmount($order->total_gross_amount, $order->currency),
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

        // Add customer if available
        if ($order->people_id && $order->people && $email = $order->getEmail()) {
            $sessionData['customer_email'] = $email;
        }

        // Create checkout session
        $session = $this->stripe->checkout->sessions->create($sessionData);

        // Store session info in order
        $order->addMetadata('stripe_session_id', $session->id);
        $order->addMetadata('stripe_checkout_url', $session->url);

        return $session;
    }

    /**
     * Create detailed checkout session with individual line items
     */
    public function createDetailedCheckoutSession(Order $order, array $options = []): \Stripe\Checkout\Session
    {
        $this->validateOrder($order);

        $lineItems = [];

        // Add each order item as a line item
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

        // Add shipping as separate line item if applicable
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

        // Add customer if available
        if ($order->people_id && $order->people && $email = $order->getEmail()) {
            $sessionData['customer_email'] = $email;
        }

        $session = $this->stripe->checkout->sessions->create($sessionData);

        $order->addMetadata('stripe_session_id', $session->id);
        $order->addMetadata('stripe_checkout_url', $session->url);

        return $session;
    }

    /**
     * Get order description for simple payment
     */
    protected function getOrderDescription(Order $order): string
    {
        $itemCount = $order->items->count();
        $itemText = $itemCount === 1 ? 'item' : 'items';

        return "Order containing {$itemCount} {$itemText}";
    }

    /**
     * Convert amount to Stripe format
     */
    protected function convertToStripeAmount(float $amount, ?string $currency = 'USD'): int
    {
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'clp'];
        $currency = strtolower($currency ?? 'usd');

        return in_array($currency, $zeroDecimalCurrencies)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    /**
     * Validate order
     */
    protected function validateOrder(Order $order): void
    {
        if ($order->total_gross_amount <= 0) {
            throw new ValidationException('Order total must be greater than 0');
        }
    }

    /**
     * Get default success URL
     */
    protected function getDefaultSuccessUrl(Order $order): string
    {
        return $this->app->get(ConfigurationEnum::CHECKOUT_SUCCESS_URL->value) . "/orders/{$order->id}/success?session_id={CHECKOUT_SESSION_ID}";
    }

    /**
     * Get default cancel URL
     */
    protected function getDefaultCancelUrl(Order $order): string
    {
        return $this->app->get(ConfigurationEnum::CHECKOUT_CANCEL_URL->value) . "/orders/{$order->id}/cancel?session_id={CHECKOUT_SESSION_ID}";
    }

    /**
     * Retrieve checkout session by order
     */
    public function getCheckoutSessionByOrder(Order $order): ?\Stripe\Checkout\Session
    {
        $sessionId = $order->getMetadata('stripe_session_id');

        if (! $sessionId) {
            return null;
        }

        try {
            return $this->stripe->checkout->sessions->retrieve($sessionId);
        } catch (\Exception $e) {
            return null;
        }
    }
}
