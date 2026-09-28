<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use stdClass;
use Tests\TestCase;

class ParticipantProfileFieldsTest extends TestCase
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

    public function test_nivel_and_area_round_trip_as_people_custom_fields(): void
    {
        $people = $this->seedPeople();

        $mapped = ParticipantMapper::fromIntras(
            $this->participantRow([
                'participants_levels_id' => 120151,
                'themes_areas_id' => 8,
            ]),
            [],
            $this->lookupNames()
        );

        $this->writeCustomFields($people, $mapped['custom_fields']);

        $this->assertSame('Gerencia media', $people->get('nivel'));
        $this->assertSame('Recursos Humanos', $people->get('area'));
    }

    public function test_unresolvable_lookup_leaves_the_person_without_the_field(): void
    {
        $people = $this->seedPeople();

        // Mirrors an install where participants_levels is an assignment table with no
        // name column, or the catalog row was deleted: we store nothing rather than an id.
        $mapped = ParticipantMapper::fromIntras(
            $this->participantRow([
                'participants_levels_id' => 999999,
                'themes_areas_id' => 8,
            ]),
            [],
            $this->lookupNames()
        );

        $this->writeCustomFields($people, $mapped['custom_fields']);

        $this->assertNull($people->get('nivel'));
        $this->assertSame('Recursos Humanos', $people->get('area'));
    }

    private function writeCustomFields(People $people, array $customFields): void
    {
        foreach ($customFields as $name => $value) {
            if ($value !== null) {
                $people->set($name, $value);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lookupNames(): array
    {
        return [
            'participants_levels' => [120151 => 'Gerencia media'],
            'themes_areas' => [8 => 'Recursos Humanos'],
            'departments' => [],
        ];
    }

    private function participantRow(array $overrides = []): stdClass
    {
        $defaults = [
            'first_name' => 'Maria',
            'last_name' => 'Perez',
            'position' => 'Coordinadora',
            'identification' => null,
            'is_prospect' => 0,
            'classification' => null,
            'participants_levels_id' => null,
            'themes_areas_id' => null,
            'departments_id' => null,
        ];

        $row = new stdClass();
        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }

    private function seedPeople(): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->kanvasCompany->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => 'Maria',
            'lastname' => 'Perez ' . uniqid(),
            'name' => 'Maria Perez',
        ]);
    }
}
