<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Shipping\Enums\ShippingConditionEnum;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxResult;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxConditionEnum;
use Kanvas\Souk\Taxes\Enums\TaxTreatmentEnum;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Kanvas\Users\Models\Users;
use Throwable;
use Wearepixel\Cart\Cart;
use Wearepixel\Cart\CartCondition;

class EstimateCartTaxAction
{
    private ?TaxContext $context = null;
    private ?TaxProviderInterface $provider = null;

    public function __construct(
        private readonly Cart $cart,
        private readonly Apps $app,
        private readonly ?Users $user = null,
    ) {
    }

    public function executeFailOpen(): void
    {
        try {
            $this->execute();
        } catch (Throwable) {
        }
    }

    public function context(): ?TaxContext
    {
        return $this->context;
    }

    public function provider(): ?TaxProviderInterface
    {
        return $this->provider;
    }

    public function execute(): ?TaxResult
    {
        if (! $this->app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value)) {
            return null;
        }

        if ($this->cart->isEmpty()) {
            $this->cart->removeCartCondition(TaxConditionEnum::NAME->value);

            return null;
        }

        $context = $this->context = $this->buildContext();

        if (! $context->shipTo->country) {
            $this->writeCondition(
                conditionValue: '+0',
                attributes: [
                    'status' => 'unsupported',
                    'provider' => null,
                    'components' => [],
                    'input_hash' => $context->inputHash(),
                    'calculated_at' => Carbon::now()->toIso8601String(),
                ],
            );

            return null;
        }

        try {
            $provider = $this->provider = new TaxProviderResolver($this->app)->resolve($context);
            $result = $provider->calculate($context);
        } catch (Throwable) {
            $this->writeCondition(
                conditionValue: '+0',
                attributes: [
                    'status' => 'failed',
                    'provider' => null,
                    'components' => [],
                    'input_hash' => $context->inputHash(),
                    'calculated_at' => Carbon::now()->toIso8601String(),
                ],
            );

            return null;
        }

        if ($result->treatment === TaxTreatmentEnum::NOT_COLLECTING || $result->treatment === TaxTreatmentEnum::NO_BASE) {
            $this->writeCondition(
                conditionValue: '+0',
                attributes: [
                    'status' => 'unsupported',
                    'provider' => null,
                    'components' => [],
                    'input_hash' => $context->inputHash(),
                    'calculated_at' => Carbon::now()->toIso8601String(),
                ],
            );

            return null;
        }

        $isInclusive = $result->treatment === TaxTreatmentEnum::ZERO_RATED
            || $result->treatment === TaxTreatmentEnum::EXEMPT;

        $totalTax = (float) $result->totalTax;
        $conditionValue = $isInclusive ? '+0' : '+' . number_format($totalTax, 2, '.', '');
        $mode = $isInclusive ? 'inclusive' : 'exclusive';

        $components = array_map(fn ($component) => [
            'line_id' => $component->lineId,
            'rate' => (float) $component->rate,
            'amount' => (float) $component->taxAmount,
            'treatment' => $component->taxabilityReason ?? $result->treatment->value,
        ], $result->components);

        $this->writeCondition(
            conditionValue: $conditionValue,
            attributes: [
                'status' => 'calculated',
                'provider' => 'manual',
                'amount' => $totalTax,
                'mode' => $mode,
                'components' => $components,
                'input_hash' => $context->inputHash(),
                'calculated_at' => Carbon::now()->toIso8601String(),
            ],
        );

        return $result;
    }

    private function writeCondition(string $conditionValue, array $attributes): void
    {
        $this->cart->removeCartCondition(TaxConditionEnum::NAME->value);

        $this->cart->condition(new CartCondition([
            'name' => TaxConditionEnum::NAME->value,
            'type' => TaxConditionEnum::TYPE->value,
            'target' => 'total',
            'value' => $conditionValue,
            'order' => 50,
            'attributes' => $attributes,
        ]));
    }

    private function buildContext(): TaxContext
    {
        $shipTo = $this->resolveShipTo();
        $shipFrom = TaxAddress::shipFromConfig($this->app);
        $lines = $this->buildLines();
        $exemption = CustomerTaxExemption::activeForPeople($this->app, $this->resolvePeopleId());

        return new TaxContext(
            appId: (string) $this->app->getId(),
            shipTo: $shipTo,
            shipFrom: $shipFrom,
            lines: $lines,
            currency: $this->resolveCurrency(),
            cartSessionId: $this->cart->getSessionKey(),
            reference: $this->cart->getSessionKey(),
            exemptionType: $exemption?->exemption_type,
            customerId: $exemption !== null ? (string) $exemption->people_id : null,
            taxDate: Carbon::today()->format('Y-m-d'),
        );
    }

    private function resolvePeopleId(): ?int
    {
        if ($this->user === null) {
            return null;
        }

        $peopleId = $this->user->getAppProfile($this->app)->people_id;

        return $peopleId !== null ? (int) $peopleId : null;
    }

    private function resolveShipTo(): TaxAddress
    {
        $shippingCondition = $this->cart->getCondition(ShippingConditionEnum::NAME->value);

        if ($shippingCondition === null) {
            return new TaxAddress(country: null, state: null, city: null, postalCode: null);
        }

        $destination = $shippingCondition->getAttributes()[ShippingConditionEnum::DESTINATION->value] ?? [];

        return new TaxAddress(
            country: isset($destination['country']) ? strtoupper((string) $destination['country']) : null,
            state: null,
            city: isset($destination['city']) ? (string) $destination['city'] : null,
            postalCode: isset($destination['postal_code']) ? (string) $destination['postal_code'] : null,
        );
    }

    private function buildLines(): array
    {
        $creditReplacesPrice = (bool) $this->app->get(
            WalletConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value
        );

        $lines = [];

        foreach ($this->cart->getContent() as $item) {
            $variantId = (int) $item['id'];
            $variant = Variants::find($variantId);

            $lines[] = TaxableLine::forVariant(
                variant: $variant,
                creditReplacesPrice: $creditReplacesPrice,
                lineId: $variant?->uuid ?? (string) $variantId,
                reference: (string) $variantId,
                unitPrice: (float) ($item['price'] ?? 0),
                quantity: (int) ($item['quantity'] ?? 1),
            );
        }

        if ($this->cart->getCondition(ShippingConditionEnum::NAME->value) !== null) {
            $shippingValue = (float) $this->cart->getCalculatedValueForCondition(ShippingConditionEnum::NAME->value);

            if ($shippingValue > 0) {
                $lines[] = TaxableLine::shipping('shipping-' . $this->cart->getSessionKey(), $shippingValue);
            }
        }

        return $lines;
    }

    private function resolveCurrency(): string
    {
        $content = $this->cart->getContent();

        if ($content->isEmpty()) {
            return 'DOP';
        }

        $firstItem = $content->first();
        $currency = $firstItem['attributes']['currency']['code'] ?? null;

        return $currency !== null ? (string) $currency : 'DOP';
    }
}
