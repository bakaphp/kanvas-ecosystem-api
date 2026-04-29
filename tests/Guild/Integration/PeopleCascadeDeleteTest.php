<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Dyrynda\Database\Support\CascadeSoftDeletes;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use ReflectionClass;
use Tests\TestCase;

final class PeopleCascadeDeleteTest extends TestCase
{
    public function testDeletePeopleCascadesToContactsAndAddresses(): void
    {
        $people = $this->createPeopleWithContactsAndAddress();
        $contactIds = $people->contacts->pluck('id')->all();
        $addressIds = $people->address->pluck('id')->all();

        $this->assertNotEmpty($contactIds, 'fixture must have contacts');
        $this->assertNotEmpty($addressIds, 'fixture must have addresses');

        $people->delete();

        $this->assertSame(
            1,
            (int) People::withTrashed()->find($people->getId())->is_deleted,
            'People must be soft-deleted'
        );
        foreach ($contactIds as $id) {
            $this->assertSame(
                1,
                (int) Contact::withTrashed()->find($id)->is_deleted,
                "Contact {$id} must be soft-deleted together with the person"
            );
        }
        foreach ($addressIds as $id) {
            $this->assertSame(
                1,
                (int) Address::withTrashed()->find($id)->is_deleted,
                "Address {$id} must be soft-deleted together with the person"
            );
        }
    }

    public function testCascadeIsSoftNotHard(): void
    {
        $people = $this->createPeopleWithContactsAndAddress();
        $contactIds = $people->contacts->pluck('id')->all();
        $addressIds = $people->address->pluck('id')->all();

        $people->delete();

        $this->assertNotNull(
            People::withTrashed()->find($people->getId()),
            'People row must still exist after delete (soft, not hard)'
        );
        foreach ($contactIds as $id) {
            $this->assertNotNull(
                Contact::withTrashed()->find($id),
                "Contact {$id} row must still exist after cascade (soft, not hard)"
            );
        }
        foreach ($addressIds as $id) {
            $this->assertNotNull(
                Address::withTrashed()->find($id),
                "Address {$id} row must still exist after cascade (soft, not hard)"
            );
        }
    }

    public function testCascadedRecordsAreHiddenFromDefaultScope(): void
    {
        $people = $this->createPeopleWithContactsAndAddress();
        $contactIds = $people->contacts->pluck('id')->all();
        $addressIds = $people->address->pluck('id')->all();

        $people->delete();

        foreach ($contactIds as $id) {
            $this->assertNull(
                Contact::find($id),
                "Contact {$id} must be excluded by the default soft-delete scope after cascade"
            );
        }
        foreach ($addressIds as $id) {
            $this->assertNull(
                Address::find($id),
                "Address {$id} must be excluded by the default soft-delete scope after cascade"
            );
        }
    }

    public function testSoftDeleteFlipsIsDeletedWithoutCascading(): void
    {
        $people = $this->createPeopleWithContactsAndAddress();
        $contactIds = $people->contacts->pluck('id')->all();
        $addressIds = $people->address->pluck('id')->all();

        $result = $people->softDelete();

        $this->assertTrue($result, 'softDelete() must return true on success');
        $this->assertSame(
            1,
            (int) People::withTrashed()->find($people->getId())->is_deleted,
            'softDelete() must flip is_deleted on the person'
        );
        foreach ($contactIds as $id) {
            $this->assertSame(
                0,
                (int) Contact::withTrashed()->find($id)->is_deleted,
                "Contact {$id} must stay active: softDelete() fires softDeleting, not Laravel deleting, so CascadeSoftDeletes does not run"
            );
        }
        foreach ($addressIds as $id) {
            $this->assertSame(
                0,
                (int) Address::withTrashed()->find($id)->is_deleted,
                "Address {$id} must stay active: softDelete() fires softDeleting, not Laravel deleting, so CascadeSoftDeletes does not run"
            );
        }
    }

    public function testCascadeConfigurationIsDeclared(): void
    {
        $people = new People();
        $reflection = new ReflectionClass($people);
        $cascadeDeletes = $reflection->getProperty('cascadeDeletes')->getValue($people);

        $this->assertContains(
            'contacts',
            $cascadeDeletes,
            'People must declare contacts in $cascadeDeletes so CascadeSoftDeletes runs on delete'
        );
        $this->assertContains(
            'address',
            $cascadeDeletes,
            'People must declare address in $cascadeDeletes so CascadeSoftDeletes runs on delete'
        );
        $this->assertTrue(
            in_array(CascadeSoftDeletes::class, class_uses_recursive($people), true),
            'People must use the CascadeSoftDeletes trait'
        );
    }

    public function testGraphqlDeletePeopleCascades(): void
    {
        $people = $this->createPeopleWithContactsAndAddress();
        $contactIds = $people->contacts->pluck('id')->all();
        $addressIds = $people->address->pluck('id')->all();

        $this->graphQL('
            mutation($id: ID!) {
                deletePeople(id: $id)
            }
        ', ['id' => $people->getId()])->assertJson([
            'data' => ['deletePeople' => true],
        ]);

        $this->assertSame(
            1,
            (int) People::withTrashed()->find($people->getId())->is_deleted,
        );
        foreach ($contactIds as $id) {
            $this->assertSame(
                1,
                (int) Contact::withTrashed()->find($id)->is_deleted,
                "deletePeople mutation must cascade-delete contact {$id}"
            );
        }
        foreach ($addressIds as $id) {
            $this->assertSame(
                1,
                (int) Address::withTrashed()->find($id)->is_deleted,
                "deletePeople mutation must cascade-delete address {$id}"
            );
        }
    }

    private function createPeopleWithContactsAndAddress(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts()
            ->create();

        $people->address()->create([
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip' => fake()->postcode(),
            'is_default' => 1,
        ]);

        return $people->refresh()->load(['contacts', 'address']);
    }
}
