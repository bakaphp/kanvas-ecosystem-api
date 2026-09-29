<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use stdClass;
use Tests\TestCase;

/**
 * `companies_offices` is the only address in SIPGO — `participants` has no address columns, so
 * dirección / país / ciudad / sector all come through the participant's office. It was entirely
 * unmapped, which silently killed four Gestor filters and four export columns.
 */
class ParticipantOfficeAddressTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->kanvasCompany = static::$cachedUser->getCurrentCompany();
    }

    public function test_the_office_lands_on_the_person_as_an_address(): void
    {
        $people = $this->seedPeople();

        $this->action()->attach($people, $this->mappedAddress());

        $address = $people->address()->first();

        $this->assertNotNull($address, 'the participant should have an address');
        $this->assertSame('Av. Winston Churchill 1099', $address->address);
        $this->assertSame('Santo Domingo', $address->city);
    }

    /**
     * A Dominican sector has no slot on peoples_address; putting it in `state` would misfile it
     * as a province, so it is a custom field.
     */
    public function test_the_sector_is_stored_as_a_custom_field_not_as_state(): void
    {
        $people = $this->seedPeople();

        $this->action()->attach($people, $this->mappedAddress());

        $this->assertSame('Piantini', $people->get('sector'));
    }

    public function test_reimporting_updates_the_address_instead_of_adding_a_second(): void
    {
        $people = $this->seedPeople();
        $action = $this->action();

        $action->attach($people, $this->mappedAddress());
        $action->attach($people, [
            'address' => 'Calle El Sol 45',
            'city' => 'Santiago',
            'country' => 'República Dominicana',
            'sector' => 'Centro',
        ]);

        $this->assertSame(1, $people->address()->count(), 're-import must not append a second address');
        $this->assertSame('Calle El Sol 45', $people->address()->first()->address);
    }

    /**
     * @return array{address: ?string, city: ?string, country: ?string, sector: ?string}
     */
    private function mappedAddress(): array
    {
        $office = new stdClass();
        $office->address = 'Av. Winston Churchill 1099';
        $office->countries_id = 61;
        $office->cities_id = 302;
        $office->districts_id = 14;

        return ParticipantMapper::addressFromOffice($office, [
            'countries' => [61 => 'República Dominicana'],
            'cities' => [302 => 'Santo Domingo'],
            'districts' => [14 => 'Piantini'],
        ]);
    }

    private function action(): object
    {
        return new class (
            $this->kanvasApp,
            $this->kanvasCompany,
            static::$cachedUser,
        ) extends PullParticipantsFromIntrasAction {
            public function attach(People $people, array $address): void
            {
                $this->attachAddressToPeople($people, $address);
            }
        };
    }

    private function seedPeople(): People
    {
        $people = new People();
        $people->apps_id = $this->kanvasApp->getId();
        $people->companies_id = $this->kanvasCompany->getId();
        $people->users_id = static::$cachedUser->getId();
        $people->firstname = 'Office';
        $people->lastname = 'Test ' . uniqid();
        $people->name = $people->firstname . ' ' . $people->lastname;
        // saveQuietly() skips UuidTrait's creating hook, and the column is NOT NULL with no
        // default — a local database that has drifted a default hides this until CI.
        $people->generateUuidIfMissing()->saveQuietly();

        return $people;
    }
}
