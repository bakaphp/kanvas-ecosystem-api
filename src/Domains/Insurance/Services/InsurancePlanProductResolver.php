<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class InsurancePlanProductResolver
{
    private ?int $insurerCompanyId = null;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $credentialCompany,
    ) {
    }

    public function identify(Variants $variant): ?array
    {
        $product = $variant->product;

        if ($product === null) {
            return null;
        }

        $provider = $this->attribute($product, InsuranceCustomFieldEnum::PROVIDER);
        $code = $this->attribute($product, InsuranceCustomFieldEnum::PRODUCT_CODE);

        if ($provider !== null && $code !== null) {
            return ['provider' => $provider, 'code' => $code];
        }

        return $this->identifyBySlug((string) $product->slug);
    }

    public function activePlan(string $provider, string $code): ?InsurancePlan
    {
        return InsurancePlan::query()
            ->fromInsurerTenant($this->app, $this->credentialCompany)
            ->notDeleted()
            ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
            ->where('external_code', $code)
            ->whereHas(
                'insurer',
                fn ($insurer) => $insurer->notDeleted()
                    ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
                    ->where('provider', $provider)
            )
            ->orderBy('id')
            ->first();
    }

    public function variantFor(InsurancePlan $plan): ?Variants
    {
        $this->insurerCompanyId ??= (int) $this->credentialCompany->get(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value);

        $product = Products::query()
            ->where('apps_id', $this->app->getId())
            ->where('slug', InsuranceProductSlug::for($plan->insurer->provider, (string) $plan->external_code))
            ->where('is_published', 1)
            ->notDeleted()
            ->orderByRaw('companies_id = ? desc', [$this->insurerCompanyId])
            ->first();

        return $product?->variants()->notDeleted()->first();
    }

    private function identifyBySlug(string $slug): ?array
    {
        $plans = InsurancePlan::query()
            ->fromInsurerTenant($this->app, $this->credentialCompany)
            ->notDeleted()
            ->with('insurer')
            ->get();

        foreach ($plans as $plan) {
            if (InsuranceProductSlug::for($plan->insurer->provider, (string) $plan->external_code) === $slug) {
                return ['provider' => $plan->insurer->provider, 'code' => (string) $plan->external_code];
            }
        }

        return null;
    }

    private function attribute(Products $product, InsuranceCustomFieldEnum $field): ?string
    {
        $value = $product->getAttributeBySlug(Str::slug($field->value))?->value;

        return Str::trimToNull(is_scalar($value) ? (string) $value : null);
    }
}
