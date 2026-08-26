<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class RepairPeopleAddressesCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testItDemotesAPreviousHomeAndPromotesTheCurrentAddress(): void
    {
        $people = $this->makePeople();

        $previous = $this->seedAddress($people, '7028 Rein Ave Apt 5102', 'Whitestown', isDefault: true, type: AddressTypeEnum::PREVIOUS_HOME);
        $current = $this->seedAddress($people, '350 Monon Blvd', 'Carmel', isDefault: true);

        $this->repair($people);

        $this->assertSame(0, (int) $previous->refresh()->is_default);
        $this->assertSame(1, (int) $current->refresh()->is_default);
    }

    public function testItCollapsesDuplicateDefaultsToOne(): void
    {
        $people = $this->makePeople();

        $this->seedAddress($people, '1700 Broadway', 'New York', isDefault: true);
        $newest = $this->seedAddress($people, '350 Monon Blvd', 'Carmel', isDefault: true);

        $this->repair($people);

        $defaults = $people->address()->where('is_default', 1)->get();

        $this->assertCount(1, $defaults);
        $this->assertSame($newest->getKey(), $defaults->first()->getKey());
    }

    public function testItPromotesTheNewestWhenNothingIsFlagged(): void
    {
        $people = $this->makePeople();

        $this->seedAddress($people, '1700 Broadway', 'New York', isDefault: false);
        $newest = $this->seedAddress($people, '350 Monon Blvd', 'Carmel', isDefault: false);

        $this->repair($people);

        $this->assertSame($newest->getKey(), $people->getDefaultAddress()?->getKey());
    }

    public function testItUnmanglesAMultiLineStreet(): void
    {
        $people = $this->makePeople();

        $address = $this->seedAddress($people, '350 Monon Blvdhex0d;Hex0a;Apt 315', 'Carmel', isDefault: true);

        $this->repair($people);

        $address->refresh();

        $this->assertSame('350 Monon Blvd', $address->address);
        $this->assertSame('Apt 315', $address->address_2);
    }

    public function testDryRunWritesNothing(): void
    {
        $people = $this->makePeople();

        $previous = $this->seedAddress($people, '7028 Rein Ave', 'Whitestown', isDefault: true, type: AddressTypeEnum::PREVIOUS_HOME);
        $this->seedAddress($people, '350 Monon Blvdhex0d;Hex0a;Apt 315', 'Carmel', isDefault: false);

        $this->repair($people, dryRun: true);

        $this->assertSame(1, (int) $previous->refresh()->is_default);
        $this->assertSame(
            '350 Monon Blvdhex0d;Hex0a;Apt 315',
            $people->address()->orderByDesc('id')->first()->address
        );
    }

    private function repair(People $people, bool $dryRun = false): void
    {
        // Scoped to the seeded person: the shared dev DB carries ~470k address rows and an
        // unfiltered sweep turns every test into a full-table walk.
        $this->artisan('kanvas-guild:repair-people-addresses', array_filter([
            '--peoples_id' => [$people->getId()],
            '--dry-run' => $dryRun,
        ]))->assertSuccessful();
    }

    private function seedAddress(
        People $people,
        string $street,
        string $city,
        bool $isDefault,
        ?AddressTypeEnum $type = null
    ): Address {
        $address = new Address([
            'address' => $street,
            'city' => $city,
            'state' => 'IN',
            'zip' => '46032',
            'countries_id' => 230,
            'address_type_id' => AddressType::getByName(
                ($type ?? AddressTypeEnum::HOME)->value
            )->getId(),
        ]);

        $people->address()->save($address);

        // Written past the model so the command sees the exact pre-fix shape on disk.
        $address->forceFill(['is_default' => $isDefault ? 1 : 0])->saveOrFail();

        return $address;
    }

    private function makePeople(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        return $people;
    }
}
