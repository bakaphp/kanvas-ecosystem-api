<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;

class BuildTaxContextAction
{
    public function __construct(
        private readonly Order $order,
        private readonly Apps $app,
    ) {
    }

    public function execute(): TaxContext
    {
        $shipTo = $this->buildShipTo();
        $shipFrom = $this->buildShipFrom();
        $lines = $this->buildLines();
        $exemption = $this->resolveExemption();

        return new TaxContext(
            appId: (string) $this->app->getId(),
            shipTo: $shipTo,
            shipFrom: $shipFrom,
            lines: $lines,
            currency: $this->order->currency ?? 'DOP',
            cartSessionId: $this->order->getMetadata('cart_session_id') ?? $this->order->checkout_token,
            reference: $this->order->reference ?? $this->order->uuid,
            exemptionType: $exemption?->exemption_type,
            customerId: $exemption !== null ? (string) $exemption->people_id : null,
            taxDate: Carbon::today()->format('Y-m-d'),
        );
    }

    private function buildShipTo(): TaxAddress
    {
        $address = $this->order->shippingAddress ?? $this->order->billingAddress;

        if ($address === null) {
            return new TaxAddress(country: null, state: null, city: null, postalCode: null);
        }

        return $this->addressToTaxAddress($address);
    }

    private function buildShipFrom(): ?TaxAddress
    {
        $raw = $this->app->get('souk_tax_ship_from');

        if (! is_array($raw) || empty($raw['country'])) {
            return null;
        }

        return new TaxAddress(
            country: isset($raw['country']) ? (string) $raw['country'] : null,
            state: isset($raw['state']) ? (string) $raw['state'] : null,
            city: isset($raw['city']) ? (string) $raw['city'] : null,
            postalCode: isset($raw['postal_code']) ? (string) $raw['postal_code'] : null,
            line1: isset($raw['line1']) ? (string) $raw['line1'] : null,
        );
    }

    private function addressToTaxAddress(Address $address): TaxAddress
    {
        $countryCode = $address->country?->code !== null
            ? strtoupper($address->country->code)
            : null;

        return new TaxAddress(
            country: $countryCode,
            state: $address->state ?? null,
            city: $address->city ?? null,
            postalCode: $address->zip ?? null,
            line1: $address->address ?? null,
        );
    }

    private function buildLines(): array
    {
        $useVariantCreditInsteadOfPrice = (bool) $this->app->get(
            WalletConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value
        );

        $lines = [];

        foreach ($this->order->allItems as $item) {
            $lines[] = $this->buildLineFromItem($item, $useVariantCreditInsteadOfPrice);
        }

        $shippingCost = (float) ($this->order->shipping_price_net_amount ?? 0.0);
        if ($shippingCost > 0) {
            $lines[] = $this->buildShippingLine($shippingCost);
        }

        return $lines;
    }

    private function buildLineFromItem(OrderItem $item, bool $useVariantCreditInsteadOfPrice): TaxableLine
    {
        $variant = $item->variant;

        $variantCredit = $variant !== null
            ? (float) ($variant->getAttributeBySlug(WalletConfigurationEnum::VARIANT_WALLET_CREDIT_AMOUNT->value)?->value ?? 0.0)
            : 0.0;

        $isTaxable = true;
        if ($useVariantCreditInsteadOfPrice && $variantCredit > 0.0) {
            $isTaxable = false;
        }

        $taxCategory = $variant?->getAttributeBySlug('tax_category')?->value
            ?? $variant?->product?->getAttributeBySlug('tax_category')?->value;

        $taxCodeOverride = $variant?->getAttributeBySlug('tax_code_override')?->value
            ?? $variant?->product?->getAttributeBySlug('tax_code_override')?->value;

        if ($taxCategory !== null && TaxCategoryEnum::tryFrom($taxCategory) === TaxCategoryEnum::NON_TAXABLE) {
            $isTaxable = false;
        }

        $lineId = $variant?->uuid ?? $item->uuid;

        return new TaxableLine(
            lineId: $lineId,
            reference: $item->uuid,
            unitPrice: number_format((float) $item->unit_price_net_amount, 2, '.', ''),
            quantity: (int) $item->quantity,
            isTaxable: $isTaxable,
            taxCategory: $taxCategory,
            taxCodeOverride: $taxCodeOverride,
            isShipping: false,
        );
    }

    private function buildShippingLine(float $shippingCost): TaxableLine
    {
        return new TaxableLine(
            lineId: 'shipping',
            reference: 'shipping-' . $this->order->uuid,
            unitPrice: number_format($shippingCost, 2, '.', ''),
            quantity: 1,
            isTaxable: true,
            taxCategory: null,
            taxCodeOverride: null,
            isShipping: true,
        );
    }

    private function resolveExemption(): ?CustomerTaxExemption
    {
        $peopleId = $this->order->people_id;

        if ($peopleId === null) {
            return null;
        }

        return CustomerTaxExemption::query()
            ->where('apps_id', $this->app->getId())
            ->where('people_id', $peopleId)
            ->notDeleted()
            ->first();
    }
}
