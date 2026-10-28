<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
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
        $shipFrom = TaxAddress::shipFromConfig($this->app);
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
        $creditReplacesPrice = (bool) $this->app->get(
            WalletConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value
        );

        $lines = [];

        foreach ($this->order->allItems as $item) {
            $lines[] = TaxableLine::forVariant(
                variant: $item->variant,
                creditReplacesPrice: $creditReplacesPrice,
                lineId: $item->variant?->uuid ?? $item->uuid,
                reference: $item->uuid,
                unitPrice: (float) $item->unit_price_net_amount,
                quantity: (int) $item->quantity,
            );
        }

        $shippingCost = (float) ($this->order->shipping_price_net_amount ?? 0.0);
        if ($shippingCost > 0) {
            $lines[] = TaxableLine::shipping('shipping-' . $this->order->uuid, $shippingCost);
        }

        return $lines;
    }

    private function resolveExemption(): ?CustomerTaxExemption
    {
        return CustomerTaxExemption::activeForPeople($this->app, $this->order->people_id);
    }
}
