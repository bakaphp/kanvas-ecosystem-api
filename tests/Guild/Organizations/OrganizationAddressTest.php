<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryCustomer;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Organizations\Actions\AddAddressToOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
use Tests\TestCase;

/**
 * Organizations were the only party in Kanvas with a free-text address while People, Companies and Users all
 * carried structured, typed, multi-row addresses. This closes that gap.
 *
 * The forcing requirement wasn't tidiness: Scribe invoices already have separate billing and shipping
 * snapshots, and Mercury's AR API rejects a half-filled address outright. A single string can't express
 * "bill here, ship there", and can't be split into fields without guessing where the city ends.
 */
final class OrganizationAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testAnOrganizationHoldsABillingAndAShippingAddressAtOnce(): void
    {
        $organization = $this->seedOrganization();

        $this->addAddress($organization, AddressTypeEnum::BILLING, '1 Billing St', 'Santo Domingo');
        $this->addAddress($organization, AddressTypeEnum::SHIPPING, '2 Shipping Ave', 'Santiago');

        $this->assertCount(2, $organization->addresses()->get());

        $billing = $organization->billingAddress();
        $this->assertNotNull($billing);
        $this->assertSame('1 Billing St', $billing->address);
        $this->assertSame('Santo Domingo', $billing->city);
    }

    public function testReAddingTheSameAddressTypeUpdatesItRatherThanStackingASecond(): void
    {
        $organization = $this->seedOrganization();

        $this->addAddress($organization, AddressTypeEnum::BILLING, '1 Old St', 'Santo Domingo');
        $this->addAddress($organization, AddressTypeEnum::BILLING, '1 Corrected St', 'Santo Domingo');

        // Otherwise "the billing address" becomes ambiguous the moment someone fixes a typo, and an invoice
        // renders whichever row it happened to find first.
        $this->assertCount(1, $organization->addresses()->get());
        $this->assertSame('1 Corrected St', $organization->billingAddress()?->address);
    }

    public function testOnlyOneAddressCanBeTheDefault(): void
    {
        $organization = $this->seedOrganization();

        $this->addAddress($organization, AddressTypeEnum::BILLING, '1 Billing St', 'Santo Domingo', isDefault: true);
        $this->addAddress($organization, AddressTypeEnum::SHIPPING, '2 Shipping Ave', 'Santiago', isDefault: true);

        $defaults = $organization->addresses()->get()->where('is_default', true);
        $this->assertCount(1, $defaults, 'Two rows both claiming to be the default is not a default.');
        $this->assertSame('2 Shipping Ave', $defaults->first()->address);
    }

    public function testBillingAddressFallsBackWhenNothingIsTaggedBilling(): void
    {
        $organization = $this->seedOrganization();

        // A company that entered exactly one address means it. Making them tag it "Billing" before an invoice
        // will render is bureaucracy, not correctness.
        $this->addAddress($organization, AddressTypeEnum::OTHER, '1 Only St', 'Santo Domingo');

        $this->assertSame('1 Only St', $organization->billingAddress()?->address);
    }

    public function testACompleteAddressMapsOntoMercurysStructuredShape(): void
    {
        $organization = $this->seedOrganization('ap@initech.test');
        $this->addAddress($organization, AddressTypeEnum::BILLING, '1 Billing St', 'Santo Domingo');

        $payload = MercuryCustomer::fromOrganization($organization, 'ap@initech.test')->toApiPayload();

        $this->assertArrayHasKey('address', $payload);
        $this->assertSame('1 Billing St', $payload['address']['address1']);
        $this->assertSame('Santo Domingo', $payload['address']['city']);
        // Mercury calls the state "region", and wants the ISO 3166-1 alpha-2 country code.
        $this->assertSame('Distrito Nacional', $payload['address']['region']);
        $this->assertSame('10101', $payload['address']['postalCode']);
        $this->assertSame('DO', $payload['address']['country']);
    }

    public function testAnIncompleteAddressIsOmittedRatherThanSentToFail(): void
    {
        $organization = $this->seedOrganization('ap@initech.test');

        // No zip, no country. Mercury validates the address all-or-nothing — sending this would be rejected
        // outright, and the caller left guessing which field was missing.
        new AddAddressToOrganizationAction(
            new AddressData(
                organization: $organization,
                type: AddressTypeEnum::BILLING,
                address: '1 Partial St',
                city: 'Santo Domingo',
            ),
        )->execute();

        $this->assertFalse($organization->billingAddress()?->isComplete());

        $payload = MercuryCustomer::fromOrganization($organization, 'ap@initech.test')->toApiPayload();

        $this->assertArrayNotHasKey('address', $payload);
        $this->assertSame('ap@initech.test', $payload['email']);
    }

    private function seedOrganization(?string $email = null): Organization
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Initech LLC',
            'email' => $email,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function addAddress(
        Organization $organization,
        AddressTypeEnum $type,
        string $street,
        string $city,
        bool $isDefault = false,
    ): void {
        $country = Countries::firstOrCreate(
            ['code' => 'DO'],
            ['name' => 'Dominican Republic']
        );

        new AddAddressToOrganizationAction(
            new AddressData(
                organization: $organization,
                type: $type,
                address: $street,
                city: $city,
                state: 'Distrito Nacional',
                zip: '10101',
                countries_id: $country->getId(),
                is_default: $isDefault,
            ),
        )->execute();
    }
}
