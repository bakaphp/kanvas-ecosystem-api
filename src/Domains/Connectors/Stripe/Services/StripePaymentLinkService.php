<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Stripe\PaymentLink;
use Stripe\StripeClient;

class StripePaymentLinkService
{
    private const int CUSTOM_FIELD_DEFAULT_VALUE_MAX_LENGTH = 50;

    protected StripeClient $stripe;

    public function __construct(
        protected AppInterface $app,
        protected ?Companies $company = null,
        ?StripeClient $stripe = null
    ) {
        if ($stripe !== null) {
            $this->stripe = $stripe;

            return;
        }

        $stripeKey = $company ? $company->get(ConfigurationEnum::STRIPE_SECRET_KEY->value) : null;
        $this->stripe = new StripeClient($stripeKey ?? $this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
    }

    /**
     * Generate a Stripe Payment Link for the given order
     */
    public function generatePaymentLink(Order $order, array $options = []): PaymentLink
    {
        // Validate order has required data
        $this->validateOrder($order);

        // Check if payment link already exists for this order
        if ($existingPaymentLinkId = $order->getMetadata('stripe_payment_link_id')) {
            try {
                return $this->stripe->paymentLinks->retrieve($existingPaymentLinkId);
            } catch (Exception $e) {
                // If retrieval fails, create a new one
            }
        }

        // Create line items from order items
        $lineItems = $this->createLineItems($order);

        // Prepare payment link data
        $paymentLinkData = [
            'line_items' => $lineItems,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->getOrderNumber(),
                'app_id' => $order->apps_id,
                'company_id' => $order->companies_id,
            ],
        ];

        // Add shipping options if applicable
        if ($this->orderRequiresShipping($order)) {
            $paymentLinkData['shipping_address_collection'] = [
                'allowed_countries' => $options['allowed_countries'] ?? ['US', 'CA'],
            ];

            if ($order->shipping_price_gross_amount > 0) {
                $paymentLinkData['shipping_options'] = $this->createShippingOptions($order);
            }
        }

        // Add customer information if available
        if ($order->people_id && $order->people) {
            $paymentLinkData['customer_creation'] = 'if_required';
            if ($email = $order->getEmail()) {
                $paymentLinkData['prefill_customer_email'] = $email;
            }
        }

        if ($afterCompletion = $this->buildAfterCompletion($options)) {
            $paymentLinkData['after_completion'] = $afterCompletion;
        }

        // Add automatic tax calculation if enabled
        if ($options['automatic_tax'] ?? false) {
            $paymentLinkData['automatic_tax'] = ['enabled' => true];
        }

        // Add custom fields if provided
        if (isset($options['custom_fields'])) {
            $paymentLinkData['custom_fields'] = $this->normalizeCustomFields($options['custom_fields']);
        }

        // Line items are built from item gross prices, so the order-level discount
        // (stored in order_discounts, reflected in discount_amount) is not yet applied.
        // Attach it as a Stripe coupon so the customer is charged the net total.
        if ($couponId = $this->createDiscountCoupon($order)) {
            $paymentLinkData['discounts'] = [['coupon' => $couponId]];
        }

        // Create the payment link
        $paymentLink = $this->stripe->paymentLinks->create($paymentLinkData);

        // Store payment link ID in order metadata
        $order->addMetadata('stripe_payment_link_id', $paymentLink->id);
        $order->addMetadata('stripe_payment_link_url', $paymentLink->url);

        return $paymentLink;
    }

    public function generatePaymentLinkFromLeadMessage(Lead $lead, Message $message, array $options = []): PaymentLink
    {
        $amount = (float) ($message->message['amount'] ?? $message->message['data']['amount'] ?? null);

        if (! $amount || $amount <= 0) {
            throw new ValidationException('Amount must be greater than 0');
        }

        // Check if payment link already exists for this order
        if ($existingPaymentLinkId = $message->get('stripe_payment_link_id')) {
            try {
                return $this->stripe->paymentLinks->retrieve($existingPaymentLinkId);
            } catch (Exception $e) {
                // If retrieval fails, create a new one
            }
        }

        $paymentLinkData = [
            'line_items' => [
            [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $options['product_name'] ?? 'Payment',
                        'description' => $options['product_description'] ?? '',
                    ],
                    'unit_amount' => $this->convertToStripeAmount($amount), // Amount in cents
                ],
                'quantity' => 1,
            ],
            ],
            'customer_creation' => 'if_required',
            'payment_method_types' => ['card'],
        ];

        /*    if ($lead->people_id && $lead->people && $email = $lead->people->getEmails()->first()?->value) {
               //$paymentLinkData['customer_creation'] = $email;
               //$paymentLinkData['prefill_customer_email'] = $email;
           } */

        if (isset($options['custom_fields'])) {
            $paymentLinkData['custom_fields'] = $this->normalizeCustomFields($options['custom_fields']);
        }

        // Add metadata if provided
        if (isset($options['metadata'])) {
            $paymentLinkData['metadata'] = $options['metadata'];
        }

        if ($afterCompletion = $this->buildAfterCompletion($options)) {
            $paymentLinkData['after_completion'] = $afterCompletion;
        }

        $paymentLink = $this->stripe->paymentLinks->create($paymentLinkData);

        // Store payment link ID in order metadata
        $message->set('stripe_payment_link_id', $paymentLink->id);
        $message->set('stripe_payment_link_url', $paymentLink->url);

        return $paymentLink;
    }

    /**
     * Stripe rejects an empty `after_completion[redirect][url]`
     * (`parameter_invalid_empty`): it reads "" as an attempt to unset the
     * param, which cannot be unset. Return null when no non-empty success_url
     * is supplied so the block is omitted entirely instead of failing the request.
     */
    protected function buildAfterCompletion(array $options): ?array
    {
        if (empty($options['success_url'])) {
            return null;
        }

        return [
            'type' => 'redirect',
            'redirect' => ['url' => $options['success_url']],
        ];
    }

    /**
     * Two Stripe constraints on a text/numeric custom field's `default_value`:
     * an empty string is read as an attempt to unset the param
     * (`parameter_invalid_empty`), and the value is capped at 50 characters
     * regardless of the field's own `maximum_length` (which may go up to 255).
     * Drop the empties and truncate the rest so a long customer name pre-fills
     * partially instead of failing the whole payment-link request.
     */
    protected function normalizeCustomFields(array $customFields): array
    {
        foreach ($customFields as &$field) {
            foreach (['text', 'numeric'] as $inputType) {
                if (! isset($field[$inputType]['default_value'])) {
                    continue;
                }

                $defaultValue = Str::trimToNull((string) $field[$inputType]['default_value']);

                if ($defaultValue === null) {
                    unset($field[$inputType]['default_value']);

                    continue;
                }

                // max(1, ...) because a field carrying maximum_length 0 (or a non-numeric one, which
                // casts to 0) would truncate to '' — reintroducing the parameter_invalid_empty this
                // method exists to prevent.
                $maxLength = max(1, min(
                    self::CUSTOM_FIELD_DEFAULT_VALUE_MAX_LENGTH,
                    (int) ($field[$inputType]['maximum_length'] ?? self::CUSTOM_FIELD_DEFAULT_VALUE_MAX_LENGTH)
                ));

                $field[$inputType]['default_value'] = mb_substr($defaultValue, 0, $maxLength);
            }
        }
        unset($field);

        return $customFields;
    }

    /**
     * Create line items from order items
     */
    protected function createLineItems(Order $order): array
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            // Create or get existing price object
            $priceId = $this->getOrCreatePrice($item);

            $lineItems[] = [
                'price' => $priceId,
                'quantity' => $item->quantity,
            ];
        }

        return $lineItems;
    }

    /**
     * Get or create a Stripe price for the order item
     */
    protected function getOrCreatePrice($orderItem): string
    {
        // Check if price already exists for this item
        $priceKey = 'stripe_price_' . md5($orderItem->product_sku . '_' . $orderItem->unit_price_gross_amount);

        if ($existingPriceId = $orderItem->getMetadata($priceKey)) {
            try {
                $this->stripe->prices->retrieve($existingPriceId);

                return $existingPriceId;
            } catch (Exception $e) {
                // If price doesn't exist, create a new one
            }
        }

        // Create new price
        $price = $this->stripe->prices->create([
            'currency' => strtolower($orderItem->currency ?? 'usd'),
            'unit_amount' => $this->convertToStripeAmount($orderItem->unit_price_gross_amount, $orderItem->currency),
            'product_data' => [
                'name' => $orderItem->product_name,
                'metadata' => [
                    'sku' => $orderItem->product_sku,
                    'variant_id' => $orderItem->variant_id,
                ],
            ],
        ]);

        // Store price ID for future use
        $orderItem->addMetadata($priceKey, $price->id);

        return $price->id;
    }

    /**
     * Create a one-time Stripe coupon for the order-level discount.
     *
     * Returns null when the order has no discount. The coupon's amount_off carries the
     * discount in the order's currency so Stripe subtracts it from the gross line items.
     */
    protected function createDiscountCoupon(Order $order): ?string
    {
        $discountAmount = (float) $order->discount_amount;

        if ($discountAmount <= 0) {
            return null;
        }

        $coupon = $this->stripe->coupons->create([
            'amount_off' => $this->convertToStripeAmount($discountAmount, $order->currency),
            'currency' => strtolower($order->currency ?? 'usd'),
            'duration' => 'once',
            'name' => $order->translated_discount_name ?? $order->discount_name ?? 'Discount',
            'metadata' => [
                'order_id' => $order->id,
                'voucher_id' => $order->voucher_id,
            ],
        ]);

        return $coupon->id;
    }

    /**
     * Create shipping options for the payment link
     */
    protected function createShippingOptions(Order $order): array
    {
        return [
            [
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'fixed_amount' => [
                        'amount' => $this->convertToStripeAmount($order->shipping_price_gross_amount, $order->currency),
                        'currency' => strtolower($order->currency ?? 'usd'),
                    ],
                    'display_name' => $order->shipping_method_name ?? 'Standard Shipping',
                ],
            ],
        ];
    }

    /**
     * Check if order requires shipping
     */
    protected function orderRequiresShipping(Order $order): bool
    {
        return $order->items->where('is_shipping_required', true)->count() > 0;
    }

    /**
     * Convert amount to Stripe's format (cents)
     */
    protected function convertToStripeAmount(float $amount, ?string $currency = 'USD'): int
    {
        // Currencies that don't use subunits (like JPY, KRW)
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'clp', 'gnf', 'kmf', 'mga', 'pyg', 'rwf', 'ugx', 'vuv', 'xaf', 'xof', 'xpf'];

        $currency = strtolower($currency ?? 'usd');

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    /**
     * Validate that order has required data for payment link
     */
    protected function validateOrder(Order $order): void
    {
        if ($order->items->isEmpty()) {
            throw new ValidationException('Order must have at least one item to create a payment link');
        }

        if ($order->total_gross_amount <= 0) {
            throw new ValidationException('Order total must be greater than 0');
        }

        if (empty($order->currency)) {
            throw new ValidationException('Order must have a currency specified');
        }
    }

    /**
     * Retrieve payment link by order
     */
    public function getPaymentLinkByOrder(Order $order): ?PaymentLink
    {
        $paymentLinkId = $order->getMetadata('stripe_payment_link_id');

        if (! $paymentLinkId) {
            return null;
        }

        try {
            return $this->stripe->paymentLinks->retrieve($paymentLinkId);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Deactivate payment link for order
     */
    public function deactivatePaymentLink(Order $order): bool
    {
        $paymentLinkId = $order->getMetadata('stripe_payment_link_id');

        if (! $paymentLinkId) {
            return false;
        }

        try {
            $this->stripe->paymentLinks->update($paymentLinkId, ['active' => false]);
            $order->addMetadata('stripe_payment_link_deactivated', true);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create payment link with customer from existing person
     */
    public function generatePaymentLinkWithCustomer(Order $order, array $options = []): PaymentLink
    {
        if ($order->people_id && $order->people) {
            $stripeCustomerService = new StripeCustomerService($this->app);
            $customer = $stripeCustomerService->getOrCreateCustomerByPerson($order->people);

            // Add customer to options
            $options['customer'] = $customer->id;
        }

        return $this->generatePaymentLink($order, $options);
    }
}
