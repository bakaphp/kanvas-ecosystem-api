<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Locations\Models\Countries;
use Tests\TestCase;

/**
 * The GraphQL surface a UI needs to manage several addresses on an Organization — mirroring what People
 * already has, which Organizations were missing.
 */
final class OrganizationAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testAddQueryAndDeleteAddressesOnAnOrganization(): void
    {
        $organization = $this->seedOrganization();
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $billing = $this->addAddress($organization, 'BILLING', '1 Billing St', $country->getId());
        $this->addAddress($organization, 'SHIPPING', '2 Shipping Ave', $country->getId());

        $response = $this->graphQL('
            query ($id: Mixed!) {
                organizations(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        addresses { id address city state zip is_default is_complete type }
                        billing_address { address is_complete }
                    }
                }
            }
        ', ['id' => $organization->getId()])->assertSuccessful();

        $data = $response->json('data.organizations.data.0');

        $this->assertCount(2, $data['addresses']);
        // Everything an external billing API needs is present, so it can actually be sent.
        $this->assertTrue($data['billing_address']['is_complete']);
        $this->assertSame('1 Billing St', $data['billing_address']['address']);

        $this->graphQL('
            mutation ($id: ID!) { deleteOrganizationAddress(id: $id) }
        ', ['id' => $billing['id']])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteOrganizationAddress' => true]]);

        $this->assertCount(1, $organization->addresses()->get());
    }

    public function testUpdatingOneFieldDoesNotWipeTheRest(): void
    {
        $organization = $this->seedOrganization();
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $address = $this->addAddress($organization, 'BILLING', '1 Billing St', $country->getId());

        // A PATCH, not a replace — correcting the zip must not silently erase the street.
        $this->graphQL('
            mutation ($id: ID!, $input: AddressInput!) {
                updateOrganizationAddress(id: $id, input: $input) { id address city zip }
            }
        ', [
            'id' => $address['id'],
            'input' => ['type' => 'BILLING', 'zip' => '99999'],
        ])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateOrganizationAddress' => [
                'address' => '1 Billing St',
                'city' => 'Santo Domingo',
                'zip' => '99999',
            ]]]);
    }

    public function testAnOrganizationCanBeCreatedWithItsAddressesInOneCall(): void
    {
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $response = $this->graphQL('
            mutation ($input: OrganizationInput!) {
                createOrganization(input: $input) {
                    id
                    name
                    addresses { address city type is_complete }
                    billing_address { address }
                }
            }
        ', ['input' => [
            'name' => 'Initech LLC',
            'email' => 'ap@initech.test',
            'addresses' => [
                [
                    'type' => 'BILLING',
                    'address' => '1 Billing St',
                    'city' => 'Santo Domingo',
                    'state' => 'Distrito Nacional',
                    'zip' => '10101',
                    'country_id' => $country->getId(),
                ],
                [
                    'type' => 'SHIPPING',
                    'address' => '2 Shipping Ave',
                    'city' => 'Santiago',
                    'state' => 'Santiago',
                    'zip' => '51000',
                    'country_id' => $country->getId(),
                ],
            ],
        ]])->assertSuccessful();

        $organization = $response->json('data.createOrganization');

        $this->assertCount(2, $organization['addresses']);
        $this->assertSame('1 Billing St', $organization['billing_address']['address']);
        $this->assertTrue($organization['addresses'][0]['is_complete']);
    }

    public function testUpdatingAnOrganizationDoesNotWipeAddressesItDidNotMention(): void
    {
        $organization = $this->seedOrganization();
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $this->addAddress($organization, 'BILLING', '1 Billing St', $country->getId());
        $this->addAddress($organization, 'SHIPPING', '2 Shipping Ave', $country->getId());

        // Editing the phone number must not silently delete the shipping address. The addresses block is
        // additive, never a replace-all.
        $this->graphQL('
            mutation ($id: ID!, $input: OrganizationInput!) {
                updateOrganization(id: $id, input: $input) { id phone }
            }
        ', [
            'id' => $organization->getId(),
            'input' => ['name' => 'Initech LLC', 'phone' => '809-555-0100'],
        ])->assertSuccessful();

        $this->assertCount(2, $organization->addresses()->get());
    }

    public function testReAddingTheSameTypeReplacesItRatherThanStacking(): void
    {
        $organization = $this->seedOrganization();
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $this->addAddress($organization, 'BILLING', '1 Old St', $country->getId());
        $this->addAddress($organization, 'BILLING', '1 Corrected St', $country->getId());

        $this->assertCount(1, $organization->addresses()->get());
        $this->assertSame('1 Corrected St', $organization->billingAddress()?->address);
    }

    /**
     * @return array<string, mixed>
     */
    private function addAddress(
        Organization $organization,
        string $type,
        string $street,
        int $countryId,
    ): array {
        $response = $this->graphQL('
            mutation ($organization_id: ID!, $input: AddressInput!) {
                addOrganizationAddress(organization_id: $organization_id, input: $input) {
                    id address city state zip is_default is_complete
                }
            }
        ', [
            'organization_id' => $organization->getId(),
            'input' => [
                'type' => $type,
                'address' => $street,
                'city' => 'Santo Domingo',
                'state' => 'Distrito Nacional',
                'zip' => '10101',
                'country_id' => $countryId,
            ],
        ])->assertSuccessful();

        return $response->json('data.addOrganizationAddress');
    }

    private function seedOrganization(): Organization
    {
        $user = static::$cachedUser;

        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Initech LLC',
            'email' => 'ap@initech.test',
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
