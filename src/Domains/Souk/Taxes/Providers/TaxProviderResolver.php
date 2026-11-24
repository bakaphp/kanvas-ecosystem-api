<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Providers;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Providers\StripeTaxProvider;
use Kanvas\Connectors\Stripe\Services\StripeTaxService;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\DataTransferObject\TaxCoverage;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class TaxProviderResolver
{
    public function __construct(
        private readonly Apps $app,
        private readonly ?Companies $company = null,
    ) {
    }

    public function resolve(TaxContext $context): TaxProviderInterface
    {
        if (! $this->app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value)) {
            return new NullTaxProvider();
        }

        if (! $context->shipTo->country) {
            throw new ValidationException('shipping_address_required');
        }

        $country = $context->shipTo->country;

        if (! $this->hasActiveRegistration($country)) {
            return $this->handleNotCollecting();
        }

        $rules = $this->activeRulesFor($country)->get();

        if ($rules->isEmpty()) {
            return $this->handleNotCollecting();
        }

        $primaryProvider = $rules->first()->provider;

        return match ($primaryProvider) {
            TaxProviderEnum::MANUAL->value => new ManualRateTaxProvider(
                rules: $rules->all(),
                exemption: $this->findExemption($context, $country),
            ),
            TaxProviderEnum::STRIPE_TAX->value => $this->resolveStripeTaxProvider(),
            TaxProviderEnum::NONE->value => new NullTaxProvider(),
            default => throw new InvalidArgumentException("Unknown provider: {$primaryProvider}"),
        };
    }

    public function resolveFromSnapshot(OrderTaxSnapshot $snapshot): TaxProviderInterface
    {
        return match ($snapshot->provider) {
            TaxProviderEnum::MANUAL->value => new ManualRateTaxProvider(
                rules: [new TaxRoutingRule()],
            ),
            TaxProviderEnum::STRIPE_TAX->value => $this->resolveStripeTaxProvider(),
            default => new NullTaxProvider(),
        };
    }

    public function coverage(TaxAddress $address): TaxCoverage
    {
        $country = $address->country ?? '';

        if (! $this->app->get(ConfigurationEnum::TAX_ENGINE_ENABLED->value)) {
            return TaxCoverage::unsupported($country, $address->state, 'tax_engine_disabled');
        }

        if (! $this->hasActiveRegistration($country)) {
            return TaxCoverage::unsupported($country, $address->state, 'no_active_registration');
        }

        $rule = $this->activeRulesFor($country)->first();

        if ($rule === null) {
            return TaxCoverage::unsupported($country, $address->state, 'no_routing_rule');
        }

        return new TaxCoverage(
            country: $country,
            state: $address->state,
            provider: $rule->provider,
            isSupported: $rule->provider !== TaxProviderEnum::NONE->value,
        );
    }

    private function resolveStripeTaxProvider(): TaxProviderInterface
    {
        $company = $this->company;

        if ($company === null) {
            throw new ValidationException('unsupported_stripe_account_not_owned_by_seller');
        }

        $account = StripeAccount::resolve($this->app, $company);

        if ($account->secretKey === null) {
            throw new ValidationException('unsupported_stripe_account_not_owned_by_seller');
        }

        if ($account->sharedAppAccount && ! $this->app->get(ConfigurationEnum::STRIPE_TAX_SAME_LEGAL_ENTITY_AS_APP_ACCOUNT->value)) {
            throw new ValidationException('unsupported_stripe_account_not_owned_by_seller');
        }

        return new StripeTaxProvider(
            app: $this->app,
            company: $company,
            taxService: new StripeTaxService(),
        );
    }

    private function hasActiveRegistration(string $country): bool
    {
        return TaxRegistration::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('jurisdiction_country', $country)
            ->where('is_active', true)
            ->where('effective_from', '<=', today())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->exists();
    }

    private function activeRulesFor(string $country): Builder
    {
        return TaxRoutingRule::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('condition_country', $country)
            ->where('is_active', true)
            ->orderBy('priority', 'asc');
    }

    private function handleNotCollecting(): TaxProviderInterface
    {
        if ($this->app->get(ConfigurationEnum::TAX_FAIL_OPEN->value)) {
            return new NullTaxProvider();
        }

        throw new ValidationException('tax_not_configured_for_destination');
    }

    private function findExemption(TaxContext $context, string $country): ?CustomerTaxExemption
    {
        if (! $context->customerId) {
            return null;
        }

        return CustomerTaxExemption::activeForPeople($this->app, (int) $context->customerId, $country);
    }
}
