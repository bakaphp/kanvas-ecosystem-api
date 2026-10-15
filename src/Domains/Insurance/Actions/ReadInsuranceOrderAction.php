<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Support\Str;
use Closure;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\DataTransferObject\InsuranceOrderData;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Insurance\Services\InsuranceOrderMetadata;
use Kanvas\Insurance\Services\InsurancePlanProductResolver;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class ReadInsuranceOrderAction
{
    private const string LEGACY_KEY = 'insurance';

    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): InsuranceOrderData
    {
        $legacy = $this->legacyBlock();

        if ($legacy === [] && ! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($this->order)) {
            return new InsuranceOrderData(
                provider: null,
                productCode: null,
                quoteNumber: null,
                vehicle: null,
                plan: null,
                customer: null,
            );
        }

        $data = $this->dataBlock();
        $vehicle = $this->itemVariantTyped(InsuranceProductTypeEnum::VEHICLE);
        $plan = $this->itemVariantTyped(InsuranceProductTypeEnum::PLAN);
        $identity = $this->needsIdentity($legacy) && $plan !== null ? $this->identityOf($plan) : null;
        $customer = $data[InsuranceOrderMetadata::CUSTOMER] ?? null;

        return new InsuranceOrderData(
            provider: $this->firstFilled(
                $legacy['provider'] ?? null,
                $identity['provider'] ?? null,
                fn (): mixed => $this->order->get(InsuranceCustomFieldEnum::PROVIDER->value)
            ),
            productCode: $this->firstFilled(
                $legacy['product'] ?? null,
                $identity['code'] ?? null,
                fn (): mixed => $this->order->get(InsuranceCustomFieldEnum::PRODUCT_CODE->value)
            ),
            quoteNumber: $this->firstFilled(
                $legacy['quote_number'] ?? null,
                $data[InsuranceOrderMetadata::QUOTE_NUMBER] ?? null,
                fn (): mixed => $this->order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value)
            ),
            vehicle: $vehicle ?? ($legacy !== [] ? $this->firstNonPlanVariant() : null),
            plan: $plan,
            customer: $legacy === [] && is_array($customer) && $customer !== [] ? InsuranceCustomer::fromArray($customer) : null,
            legacyPayload: (array) ($legacy['payload'] ?? []),
            legacyCliente: (array) ($legacy['cliente'] ?? []),
            legacy: $legacy !== [],
            legacyComplete: $this->legacyIsComplete($legacy),
        );
    }

    private function legacyIsComplete(array $legacy): bool
    {
        return $this->firstFilled($legacy['provider'] ?? null) !== null
            && $this->firstFilled($legacy['quote_number'] ?? null) !== null;
    }

    private function legacyBlock(): array
    {
        $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];

        return (array) ($metadata[self::LEGACY_KEY] ?? []);
    }

    private function dataBlock(): array
    {
        $metadata = is_array($this->order->metadata) ? $this->order->metadata : [];

        return (array) ($metadata['data'] ?? []);
    }

    private function needsIdentity(array $legacy): bool
    {
        return Str::trimToNull(isset($legacy['provider']) ? (string) $legacy['provider'] : null) === null
            || Str::trimToNull(isset($legacy['product']) ? (string) $legacy['product'] : null) === null;
    }

    private function identityOf(Variants $plan): ?array
    {
        $resolver = new InsurancePlanProductResolver(
            $this->order->app,
            InsuranceProviderFactory::credentialCompany($this->order->app, $this->order->company)
        );

        return $resolver->identify($plan);
    }

    private function itemVariantTyped(InsuranceProductTypeEnum $type): ?Variants
    {
        return $this->order->items->first(
            fn ($item): bool => $item->variant?->product?->productsType?->slug === $type->value
        )?->variant;
    }

    private function firstNonPlanVariant(): ?Variants
    {
        return $this->order->items->first(
            fn ($item): bool => $item->variant?->product?->productsType?->slug !== InsuranceProductTypeEnum::PLAN->value
        )?->variant;
    }

    private function firstFilled(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $candidate instanceof Closure ? $candidate() : $candidate;
            $text = Str::trimToNull(is_scalar($value) ? (string) $value : null);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }
}
