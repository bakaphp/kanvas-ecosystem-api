<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * `is_default` is opt-in since the column and DTO defaults were flipped to 0/false. The risk that
 * buys is a person left with NO current address — which would break every consumer that reads one.
 * These cover the ordinary single-address case an app hits through the normal CRUD surface, where
 * nothing in the input ever mentions `is_default`.
 */
final class PeopleSingleAddressDefaultTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreatingAPersonWithOneAddressMakesItTheCurrentOne(): void
    {
        $people = $this->createPeopleWithAddresses([
            AddressData::from([
                'address' => '350 Monon Blvd',
                'city' => 'Carmel',
                'state' => 'IN',
                'zip' => '46032',
            ]),
        ]);

        $this->assertSame(1, $people->address()->count());
        $this->assertSame('350 Monon Blvd', $people->getDefaultAddress()?->address);
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    public function testCreatingAPersonWithSeveralAddressesStillResolvesExactlyOne(): void
    {
        $people = $this->createPeopleWithAddresses([
            AddressData::from([
                'address' => '350 Monon Blvd',
                'city' => 'Carmel',
                'state' => 'IN',
                'zip' => '46032',
            ]),
            AddressData::from([
                'address' => '1700 Broadway',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10019',
            ]),
        ]);

        $this->assertSame(2, $people->address()->count());
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
        $this->assertNotNull($people->getDefaultAddress());
    }

    public function testUpdatingAPersonToASingleAddressKeepsACurrentOne(): void
    {
        $people = $this->createPeopleWithAddresses([
            AddressData::from([
                'address' => '7028 Rein Ave',
                'city' => 'Whitestown',
                'state' => 'IN',
                'zip' => '46075',
            ]),
        ]);

        $app = app(Apps::class);
        $user = auth()->user();

        new UpdatePeopleAction(
            $people,
            new PeopleData(
                app: $app,
                branch: $user->getCurrentCompany()->branch,
                user: $user,
                firstname: $people->firstname,
                lastname: $people->lastname,
                contacts: new DataCollection(ContactData::class, []),
                address: new DataCollection(AddressData::class, [
                    AddressData::from([
                        'address' => '350 Monon Blvd',
                        'city' => 'Carmel',
                        'state' => 'IN',
                        'zip' => '46032',
                    ]),
                ]),
                runWorkflow: false,
            ),
        )->execute();

        $people->refresh();

        $this->assertSame('350 Monon Blvd', $people->getDefaultAddress()?->address);
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    /**
     * @param list<AddressData> $addresses
     */
    private function createPeopleWithAddresses(array $addresses): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $action = new CreatePeopleAction(
            new PeopleData(
                app: $app,
                branch: $user->getCurrentCompany()->branch,
                user: $user,
                firstname: 'Address',
                lastname: 'Default' . fake()->unique()->uuid(),
                contacts: new DataCollection(ContactData::class, []),
                address: new DataCollection(AddressData::class, $addresses),
                skipDuplicateContactCheck: true,
            ),
        );
        $action->runWorkflow = false;

        return $action->execute();
    }
}
