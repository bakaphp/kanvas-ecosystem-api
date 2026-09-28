<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PeopleCurrentAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testAddDefaultAddressDemotesThePreviousDefault(): void
    {
        $people = $this->makePeople();

        $old = $people->addDefaultAddress(AddressData::from([
            'address' => '7028 Rein Ave Apt 5102',
            'city' => 'Whitestown',
            'state' => 'IN',
            'zip' => '46075',
        ]));

        $new = $people->addDefaultAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        $this->assertSame(1, (int) $new->refresh()->is_default);
        $this->assertSame(0, (int) $old->refresh()->is_default);
        $this->assertSame($new->getKey(), $people->getDefaultAddress()?->getKey());
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    /**
     * peoples_address.is_default defaults to 1 in the DB, so a previous-home row written
     * without the flag used to come back as the person's current address.
     */
    public function testAPreviousAddressNeverBecomesTheCurrentOne(): void
    {
        $people = $this->makePeople();

        $current = $people->addDefaultAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        $previous = $people->addAddress(AddressData::from([
            'address' => '7028 Rein Ave Apt 5102',
            'city' => 'Whitestown',
            'state' => 'IN',
            'zip' => '46075',
            'is_default' => false,
        ]));

        $this->assertSame(0, (int) $previous->refresh()->is_default);
        $this->assertSame($current->getKey(), $people->getDefaultAddress()?->getKey());
    }

    /**
     * The credit-app shape: a second submission after a move sends the new street as the
     * current address and the old one as the previous address.
     */
    public function testCreditAppResubmissionAfterAMoveMovesTheCurrentAddress(): void
    {
        $people = $this->makePeople();

        $people->addDefaultAddress(AddressData::from([
            'address' => '7028 Rein Ave Apt 5102',
            'city' => 'Whitestown',
            'state' => 'IN',
            'zip' => '46075',
        ]));

        $people->addDefaultAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        $people->addAddress(AddressData::from([
            'address' => '7028 Rein Ave Apt 5102',
            'city' => 'Whitestown',
            'state' => 'IN',
            'zip' => '46075',
            'is_default' => false,
        ]));

        $default = $people->getDefaultAddress();

        $this->assertSame('350 Monon Blvd', $default?->address);
        $this->assertSame('Carmel', $default?->city);
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    public function testMangledFormLineBreaksSplitIntoTheSecondAddressLine(): void
    {
        $address = AddressData::from([
            'address' => '350 Monon Blvdhex0d;Hex0a;Apt 315',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]);

        $this->assertSame('350 Monon Blvd', $address->address);
        $this->assertSame('Apt 315', $address->address_2);
    }

    public function testRealAndEncodedLineBreaksSplitTheSameWay(): void
    {
        $this->assertSame('Apt 315', AddressData::from([
            'address' => "350 Monon Blvd\r\nApt 315",
        ])->address_2);

        $this->assertSame('Apt 315', AddressData::from([
            'address' => '350 Monon Blvd&#x0d;&#x0a;Apt 315',
        ])->address_2);

        $this->assertSame('350 Monon Blvd', AddressData::from([
            'address' => "350 Monon Blvd\r\nApt 315",
            'address_2' => 'Suite 9',
        ])->address, 'an explicit address_2 is never overwritten');
    }

    /**
     * The whole point of the flip: promotion is opt-in, so a later `addAddress()` — a Souk order's
     * billing row, an ID scan, a previous home — cannot take the crown off the current address.
     * The very first address still gets it, because a person with addresses must resolve to one.
     */
    public function testAddAddressTakesTheDefaultOnlyWhenThereIsNoneYet(): void
    {
        $people = $this->makePeople();

        $first = $people->addAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        $this->assertSame(1, (int) $first->refresh()->is_default, 'the first address has to resolve');

        $second = $people->addAddress(AddressData::from([
            'address' => '1700 Broadway',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10019',
        ]));

        $this->assertSame(0, (int) $second->refresh()->is_default);
        $this->assertSame($first->getKey(), $people->getDefaultAddress()?->getKey());
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    public function testEnsureDefaultAddressPromotesTheNewestWhenNoneIsFlagged(): void
    {
        $people = $this->makePeople();

        $people->addAddress(AddressData::from([
            'address' => '7028 Rein Ave Apt 5102',
            'city' => 'Whitestown',
            'state' => 'IN',
            'zip' => '46075',
        ]));

        $newest = $people->addAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        // Reproduce the legacy shape on disk: rows written before any of this existed.
        $people->address()->update(['is_default' => 0]);

        $this->assertSame($newest->getKey(), $people->ensureDefaultAddress()?->getKey());
        $this->assertSame(1, $people->address()->where('is_default', 1)->count());
    }

    public function testEnsureDefaultAddressLeavesAnExistingDefaultAlone(): void
    {
        $people = $this->makePeople();

        $chosen = $people->addDefaultAddress(AddressData::from([
            'address' => '350 Monon Blvd',
            'city' => 'Carmel',
            'state' => 'IN',
            'zip' => '46032',
        ]));

        $people->addAddress(AddressData::from([
            'address' => '1700 Broadway',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10019',
        ]));

        $this->assertSame($chosen->getKey(), $people->ensureDefaultAddress()?->getKey());
    }

    private function makePeople(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        return $people;
    }
}
