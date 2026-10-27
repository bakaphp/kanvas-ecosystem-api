<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum as StripeConfigurationEnum;
use Kanvas\Connectors\Stripe\Providers\StripeTaxProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\DataTransferObject\TaxAddress;
use Kanvas\Souk\Taxes\DataTransferObject\TaxContext;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;
use Kanvas\Souk\Taxes\Providers\ManualRateTaxProvider;
use Kanvas\Souk\Taxes\Providers\NullTaxProvider;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class TaxProviderResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    protected Apps $kanvasApp;
    protected Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);
        $this->kanvasApp->set('stripe_tax_same_legal_entity_as_app_account', 0);
        $this->company->del(StripeConfigurationEnum::STRIPE_SECRET_KEY->value);

        parent::tearDown();
    }

    private function makeContext(string $country = 'DO'): TaxContext
    {
        return new TaxContext(
            appId: (string) $this->kanvasApp->getId(),
            shipTo: new TaxAddress(country: $country, state: null, city: 'Santo Domingo', postalCode: null),
            shipFrom: null,
            lines: [],
            currency: 'DOP',
            taxDate: '2026-10-24',
        );
    }

    private function makeUsContext(): TaxContext
    {
        return new TaxContext(
            appId: (string) $this->kanvasApp->getId(),
            shipTo: new TaxAddress(country: 'US', state: 'CA', city: 'San Francisco', postalCode: '94105'),
            shipFrom: new TaxAddress(country: 'US', state: 'NY', city: 'New York', postalCode: '10001'),
            lines: [],
            currency: 'USD',
            taxDate: '2026-10-24',
        );
    }

    private function createRegistration(string $country = 'DO'): TaxRegistration
    {
        $registration = new TaxRegistration();
        $registration->apps_id = $this->kanvasApp->getId();
        $registration->jurisdiction_country = $country;
        $registration->jurisdiction_state = null;
        $registration->provider = 'manual';
        $registration->effective_from = '2026-01-01';
        $registration->effective_to = null;
        $registration->is_active = true;
        $registration->saveOrFail();

        return $registration;
    }

    private function createRoutingRule(string $country = 'DO', string $provider = 'manual', float $rate = 0.18): TaxRoutingRule
    {
        $rule = new TaxRoutingRule();
        $rule->apps_id = $this->kanvasApp->getId();
        $rule->destination_country = $country;
        $rule->provider = $provider;
        $rule->rate = $rate;
        $rule->priority = 100;
        $rule->tax_shipping = false;
        $rule->is_active = true;
        $rule->saveOrFail();

        return $rule;
    }

    public function testDrRegistrationAndRoutingRuleReturnsManualProvider(): void
    {
        $this->createRegistration('DO');
        $this->createRoutingRule('DO', 'manual', 0.18);

        $provider = new TaxProviderResolver($this->kanvasApp)->resolve($this->makeContext('DO'));

        $this->assertInstanceOf(ManualRateTaxProvider::class, $provider);
    }

    public function testNoRegistrationForDestinationThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tax_not_configured_for_destination');

        new TaxProviderResolver($this->kanvasApp)->resolve($this->makeContext('US'));
    }

    public function testFailOpenWithNoRegistrationReturnsNullProvider(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);

        $provider = new TaxProviderResolver($this->kanvasApp)->resolve($this->makeContext('US'));

        $this->assertInstanceOf(NullTaxProvider::class, $provider);
    }

    public function testTaxEngineDisabledReturnsNullProviderRegardlessOfRegistration(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        $this->createRegistration('DO');
        $this->createRoutingRule('DO', 'manual', 0.18);

        $provider = new TaxProviderResolver($this->kanvasApp)->resolve($this->makeContext('DO'));

        $this->assertInstanceOf(NullTaxProvider::class, $provider);
    }

    public function testUsAddressWithCompanyKeyResolvesToStripe(): void
    {
        $this->company->set(StripeConfigurationEnum::STRIPE_SECRET_KEY->value, 'sk_test_company_own_key');

        $this->createRegistration('US');
        $this->createRoutingRule('US', 'stripe_tax');

        $provider = new TaxProviderResolver($this->kanvasApp, $this->company)->resolve($this->makeUsContext());

        $this->assertInstanceOf(StripeTaxProvider::class, $provider);
    }

    public function testUsAddressWithSharedKeyNoFlagResolvesToUnsupported(): void
    {
        $this->kanvasApp->set(StripeConfigurationEnum::STRIPE_SECRET_KEY->value, 'sk_test_app_key');
        $this->company->set(StripeConfigurationEnum::STRIPE_SECRET_KEY->value, null);
        $this->kanvasApp->set('stripe_tax_same_legal_entity_as_app_account', 0);

        $this->createRegistration('US');
        $this->createRoutingRule('US', 'stripe_tax');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_stripe_account_not_owned_by_seller');

        new TaxProviderResolver($this->kanvasApp, $this->company)->resolve($this->makeUsContext());
    }
}
