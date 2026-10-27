<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Providers;

use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Providers\StripeTaxProvider;
use Kanvas\Connectors\Stripe\Services\StripeTaxService;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Contracts\TaxProviderInterface;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
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

        $rule = TaxRoutingRule::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('destination_country', $country)
            ->where('is_active', true)
            ->orderBy('priority', 'asc')
            ->first();

        if ($rule === null) {
            return $this->handleNotCollecting();
        }

        return match ($rule->provider) {
            TaxProviderEnum::MANUAL->value => new ManualRateTaxProvider(
                rule: $rule,
                exemption: $this->findExemption($context, $country),
            ),
            TaxProviderEnum::STRIPE_TAX->value => $this->resolveStripeTaxProvider(),
            TaxProviderEnum::NONE->value => new NullTaxProvider(),
            default => throw new InvalidArgumentException("Unknown provider: {$rule->provider}"),
        };
    }

    public function resolveFromSnapshot(OrderTaxSnapshot $snapshot): TaxProviderInterface
    {
        return match ($snapshot->provider) {
            TaxProviderEnum::MANUAL->value => new ManualRateTaxProvider(
                rule: new TaxRoutingRule(),
            ),
            TaxProviderEnum::STRIPE_TAX->value => $this->resolveStripeTaxProvider(),
            default => new NullTaxProvider(),
        };
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

        if ($account->sharedAppAccount && ! $this->app->get('stripe_tax_same_legal_entity_as_app_account')) {
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

        return CustomerTaxExemption::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('people_id', $context->customerId)
            ->where('jurisdiction_country', $country)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))
            ->first();
    }
}
