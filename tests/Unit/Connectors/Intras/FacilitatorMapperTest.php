<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\FacilitatorMapper;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use PHPUnit\Framework\TestCase;
use stdClass;

class FacilitatorMapperTest extends TestCase
{
    /**
     * None of a facilitator's custom fields were imported, so every one landed with no email and
     * no phone at all.
     */
    public function testExtractsContactsThatWerePreviouslyDroppedEntirely(): void
    {
        $mapped = FacilitatorMapper::fromIntras($this->facilitatorRow(), [
            'email' => ' jperez@intras.com.do ',
            'email_2' => 'jperez@gmail.com',
            'celular' => '8095550100',
            'telefono' => '8095550200',
        ]);

        $this->assertCount(4, $mapped['contacts']);
        $this->assertSame('jperez@intras.com.do', $this->find($mapped['contacts'], ContactTypeEnum::EMAIL)['value']);
        $this->assertSame('jperez@gmail.com', $this->find($mapped['contacts'], ContactTypeEnum::SECONDARY_EMAIL)['value']);
        $this->assertSame('8095550100', $this->find($mapped['contacts'], ContactTypeEnum::CELLPHONE)['value']);
    }

    public function testSkipsBlankContactValues(): void
    {
        $mapped = FacilitatorMapper::fromIntras($this->facilitatorRow(), [
            'email' => '   ',
            'celular' => '8095550100',
        ]);

        $this->assertCount(1, $mapped['contacts']);
    }

    public function testResolvesTheFacilitatorCatalogsToNames(): void
    {
        $mapped = FacilitatorMapper::fromIntras(
            $this->facilitatorRow([
                'facilitators_statuses_id' => 1,
                'affiliates_id' => 5,
                'home_countries_id' => 61,
                'home_cities_id' => 302,
            ]),
            [],
            $this->lookupNames(),
        );

        $this->assertSame('ACTIVO', $mapped['custom_fields']['estatus']);
        $this->assertSame('FranklinCovey', $mapped['custom_fields']['aliado']);
        $this->assertSame('República Dominicana', $mapped['custom_fields']['pais']);
        $this->assertSame('Santo Domingo', $mapped['custom_fields']['ciudad']);
    }

    /**
     * identification and resume are columns on the Kanvas `facilitators` table, not custom
     * fields — the importer writes them to the Facilitator row.
     */
    public function testReturnsFacilitatorTableColumnsSeparatelyFromCustomFields(): void
    {
        $mapped = FacilitatorMapper::fromIntras($this->facilitatorRow([
            'identification' => ' 00112233445 ',
            'cv' => ' 20 años de experiencia ',
        ]));

        $this->assertSame('00112233445', $mapped['identification']);
        $this->assertSame('20 años de experiencia', $mapped['resume']);
    }

    public function testKeepsAZeroEventCountRatherThanDroppingIt(): void
    {
        $mapped = FacilitatorMapper::fromIntras($this->facilitatorRow(['events_count' => 0]));

        $this->assertSame(0, $mapped['custom_fields']['events_count']);
    }

    public function testLookupTablesCoversEveryFacilitatorForeignKey(): void
    {
        $this->assertSame(
            ['facilitators_statuses', 'affiliates', 'countries', 'cities'],
            FacilitatorMapper::lookupTables()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lookupNames(): array
    {
        return [
            'facilitators_statuses' => [1 => 'ACTIVO'],
            'affiliates' => [5 => 'FranklinCovey'],
            'countries' => [61 => 'República Dominicana'],
            'cities' => [302 => 'Santo Domingo'],
        ];
    }

    private function facilitatorRow(array $overrides = []): stdClass
    {
        $defaults = [
            'id' => 1,
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'identification' => null,
            'cv' => null,
            'events_count' => null,
            'facilitators_statuses_id' => null,
            'affiliates_id' => null,
            'home_countries_id' => null,
            'home_cities_id' => null,
        ];

        $row = new stdClass();

        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }

    private function find(array $contacts, ContactTypeEnum $type): array
    {
        foreach ($contacts as $contact) {
            if ($contact['type'] === $type) {
                return $contact;
            }
        }

        $this->fail('No contact found for type ' . $type->name);
    }
}
