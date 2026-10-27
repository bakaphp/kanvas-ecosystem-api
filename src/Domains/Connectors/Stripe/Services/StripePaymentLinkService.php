<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Validation\ValidationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Exceptions\ValidationException as KanvasValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Stripe\PaymentLink;
use Stripe\StripeClient;

class StripePaymentLinkService
{
    private const int CUSTOM_FIELD_DEFAULT_VALUE_MAX_LENGTH = 50;

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

    public function generatePaymentLink(Order $order, array $options = []): PaymentLink
    {
        $this->validateOrder($order);

        if ($existingPaymentLinkId = $order->getMetadata(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value)) {
            try {
                return $this->stripe()->paymentLinks->retrieve($existingPaymentLinkId);
            } catch (Exception $e) {
            }
        }

        $lineItems = $this->createLineItems($order);

        $paymentLinkData = [
            'line_items' => $lineItems,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->getOrderNumber(),
                'app_id' => $order->apps_id,
                'company_id' => $order->companies_id,
            ],
        ];

        if ($this->orderRequiresShipping($order)) {
            $paymentLinkData['shipping_address_collection'] = [
                'allowed_countries' => $options['allowed_countries'] ?? ['US', 'CA'],
            ];

            if ($order->shipping_price_gross_amount > 0) {
                $paymentLinkData['shipping_options'] = $this->createShippingOptions($order);
            }
        }

        if ($order->people_id && $order->people) {
            $paymentLinkData['customer_creation'] = 'if_required';
            if ($email = $order->getEmail()) {
                $paymentLinkData['prefill_customer_email'] = $email;
            }
        }

        if ($afterCompletion = $this->buildAfterCompletion($options)) {
            $paymentLinkData['after_completion'] = $afterCompletion;
        }

        if ($options['automatic_tax'] ?? false) {
            $paymentLinkData['automatic_tax'] = ['enabled' => true];
        }

        if (isset($options['custom_fields'])) {
            $paymentLinkData['custom_fields'] = $this->normalizeCustomFields($options['custom_fields']);
        }

        // Line items are built from item gross prices, so the order-level discount
        // (stored in order_discounts, reflected in discount_amount) is not yet applied.
        // Attach it as a Stripe coupon so the customer is charged the net total.
        if ($couponId = $this->createDiscountCoupon($order)) {
            $paymentLinkData['discounts'] = [['coupon' => $couponId]];
        }

        $paymentLink = $this->stripe()->paymentLinks->create($paymentLinkData);

        $order->addMetadata(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value, $paymentLink->id);
        $order->addMetadata(CustomFieldEnum::STRIPE_PAYMENT_LINK_URL->value, $paymentLink->url);

        return $paymentLink;
    }

    public function generatePaymentLinkFromLeadMessage(Lead $lead, Message $message, array $options = []): PaymentLink
    {
        $amount = (float) ($message->message['amount'] ?? $message->message['data']['amount'] ?? null);

        if (! $amount || $amount <= 0) {
            throw new KanvasValidationException('Amount must be greater than 0');
        }

        if ($existingPaymentLinkId = $message->get(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value)) {
            try {
                return $this->stripe()->paymentLinks->retrieve($existingPaymentLinkId);
            } catch (Exception $e) {
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
                        'unit_amount' => $this->convertToStripeAmount($amount),
                    ],
                    'quantity' => 1,
                ],
            ],
            'customer_creation' => 'if_required',
            'payment_method_types' => ['card'],
        ];

        if (isset($options['custom_fields'])) {
            $paymentLinkData['custom_fields'] = $this->normalizeCustomFields($options['custom_fields']);
        }

        if (isset($options['metadata'])) {
            $paymentLinkData['metadata'] = $options['metadata'];
        }

        if ($afterCompletion = $this->buildAfterCompletion($options)) {
            $paymentLinkData['after_completion'] = $afterCompletion;
        }

        $paymentLink = $this->stripe()->paymentLinks->create($paymentLinkData);

        $message->set(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value, $paymentLink->id);
        $message->set(CustomFieldEnum::STRIPE_PAYMENT_LINK_URL->value, $paymentLink->url);

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

    protected function createLineItems(Order $order): array
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $priceId = $this->getOrCreatePrice($item);

            $lineItems[] = [
                'price' => $priceId,
                'quantity' => $item->quantity,
            ];
        }

        return $lineItems;
    }

    protected function getOrCreatePrice($orderItem): string
    {
        $priceKey = 'stripe_price_' . md5($orderItem->product_sku . '_' . $orderItem->unit_price_gross_amount);

        if ($existingPriceId = $orderItem->getMetadata($priceKey)) {
            try {
                $this->stripe()->prices->retrieve($existingPriceId);

                return $existingPriceId;
            } catch (Exception $e) {
            }
        }

        $price = $this->stripe()->prices->create([
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

        $coupon = $this->stripe()->coupons->create([
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

    protected function orderRequiresShipping(Order $order): bool
    {
        return $order->items->where('is_shipping_required', true)->count() > 0;
    }

    protected function convertToStripeAmount(float $amount, ?string $currency = 'USD'): int
    {
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'clp', 'gnf', 'kmf', 'mga', 'pyg', 'rwf', 'ugx', 'vuv', 'xaf', 'xof', 'xpf'];

        $currency = strtolower($currency ?? 'usd');

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    protected function validateOrder(Order $order): void
    {
        if ($order->items->isEmpty()) {
            throw new KanvasValidationException('Order must have at least one item to create a payment link');
        }

        if ($order->total_gross_amount <= 0) {
            throw new KanvasValidationException('Order total must be greater than 0');
        }

        if (empty($order->currency)) {
            throw new KanvasValidationException('Order must have a currency specified');
        }
    }

    public function getPaymentLinkByOrder(Order $order): ?PaymentLink
    {
        $paymentLinkId = $order->getMetadata(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value);

        if (! $paymentLinkId) {
            return null;
        }

        try {
            return $this->stripe()->paymentLinks->retrieve($paymentLinkId);
        } catch (Exception $e) {
            return null;
        }
    }

    public function deactivatePaymentLink(Order $order): bool
    {
        $paymentLinkId = $order->getMetadata(CustomFieldEnum::STRIPE_PAYMENT_LINK_ID->value);

        if (! $paymentLinkId) {
            return false;
        }

        try {
            $this->stripe()->paymentLinks->update($paymentLinkId, ['active' => false]);
            $order->addMetadata('stripe_payment_link_deactivated', true);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function generatePaymentLinkWithCustomer(Order $order, array $options = []): PaymentLink
    {
        if ($order->people_id && $order->people) {
            $stripeCustomerService = new StripeCustomerService($this->app);
            $customer = $stripeCustomerService->getOrCreateCustomerByPerson($order->people);
            $options['customer'] = $customer->id;
        }

        return $this->generatePaymentLink($order, $options);
    }

    /**
     * Create a Stripe Payment Link pinned to a tax snapshot.
     *
     * - Amount comes from snapshot->gateway_due (checkout_payload.totals.gateway_due)
     * - Stores snapshot uuid in the STRIPE_PAYMENT_LINK_TAX_REVISION custom field
     * - Reuses the existing link when the snapshot hasn't changed (stripe_payment_link_id present)
     * - Rejects automatic_tax: Souk owns tax, not Stripe Tax
     * - Shipping collection is disabled: Souk owns shipping
     */
    public function createLinkFromSnapshot(
        OrderTaxSnapshot $snapshot,
        array $options = []
    ): PaymentLink {
        if (isset($options['automatic_tax']) && $options['automatic_tax'] === true) {
            throw ValidationException::withMessages(['automatic_tax' => 'automatic_tax_not_allowed_with_souk_tax']);
        }

        if ($snapshot->stripe_payment_link_id) {
            try {
                return $this->stripe()->paymentLinks->retrieve($snapshot->stripe_payment_link_id);
            } catch (Exception) {
            }
        }

        $gatewayDue = (float) ($snapshot->checkout_payload['totals']['gateway_due'] ?? 0.0);
        $currency = strtolower($options['currency'] ?? 'usd');

        $taxRevisionField = [
            'key' => CustomFieldEnum::STRIPE_PAYMENT_LINK_TAX_REVISION->value,
            'label' => ['type' => 'custom', 'custom' => 'Tax Revision'],
            'type' => 'text',
            'optional' => true,
            'text' => ['default_value' => mb_substr($snapshot->uuid, 0, 50)],
        ];

        $paymentLinkData = [
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
            'custom_fields' => $this->normalizeCustomFields([$taxRevisionField]),
            'metadata' => [
                'snapshot_uuid' => $snapshot->uuid,
                'apps_id' => $snapshot->apps_id,
            ],
        ];

        if ($afterCompletion = $this->buildAfterCompletion($options)) {
            $paymentLinkData['after_completion'] = $afterCompletion;
        }

        $paymentLink = $this->stripe()->paymentLinks->create($paymentLinkData);

        $snapshot->stripe_payment_link_id = $paymentLink->id;
        $snapshot->save();

        return $paymentLink;
    }

    public function deactivateLinkFromSnapshot(OrderTaxSnapshot $snapshot): void
    {
        if (! $snapshot->stripe_payment_link_id) {
            return;
        }

        try {
            $this->stripe()->paymentLinks->update($snapshot->stripe_payment_link_id, ['active' => false]);
        } catch (Exception) {
        }
    }
}
