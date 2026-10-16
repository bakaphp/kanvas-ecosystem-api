<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\EventVersionMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

class EventVersionMapperTest extends TestCase
{
    public function testResolvesTheCatalogsKanvasDoesNotModel(): void
    {
        $fields = EventVersionMapper::customFields(
            $this->versionRow([
                'languages_id' => 1,
                'affiliates_id' => 5,
                'places_id' => 12,
                'places_areas_id' => 30,
                'setup_types_id' => 2,
            ]),
            $this->lookupNames(),
        );

        $this->assertSame('Español', $fields['idioma']);
        $this->assertSame('FranklinCovey', $fields['aliado']);
        $this->assertSame('Hotel Intercontinental', $fields['lugar']);
        $this->assertSame('Salón Caoba', $fields['sala']);
        $this->assertSame('Escuela', $fields['tipo_montaje']);
    }

    public function testSkipsACatalogFieldThatCannotBeResolved(): void
    {
        $fields = EventVersionMapper::customFields(
            $this->versionRow(['places_id' => 999999]),
            $this->lookupNames(),
        );

        $this->assertArrayNotHasKey('lugar', $fields);
    }

    /**
     * A satisfaction of 0 is a real score, not a missing one — the default array_filter callback
     * would drop it and make an unrated version look unimported.
     */
    public function testKeepsZeroSatisfactionScores(): void
    {
        $fields = EventVersionMapper::customFields($this->versionRow([
            'facilitators_satisfaction' => 0,
            'companies_satisfaction' => 0,
            'exchange_rate' => 0,
        ]));

        $this->assertSame(0, $fields['satisfaccion_facilitadores']);
        $this->assertSame(0, $fields['satisfaccion_empresas']);
        $this->assertSame(0, $fields['tasa_cambio']);
    }

    public function testCollectsTheFiveCoveredByColumnsIntoMetadata(): void
    {
        $metadata = EventVersionMapper::metadata($this->versionRow([
            'place_covered_by' => 1,
            'hotel_covered_by' => 2,
            'equip_covered_by' => 1,
            'trip_covered_by' => 0,
            'flight_covered_by' => 2,
        ]));

        $this->assertSame(
            ['lugar' => 1, 'hotel' => 2, 'equipos' => 1, 'viaje' => 0, 'vuelo' => 2],
            $metadata['covered_by']
        );
    }

    public function testOmitsCoveredByEntirelyWhenNoneAreSet(): void
    {
        $metadata = EventVersionMapper::metadata($this->versionRow());

        $this->assertArrayNotHasKey('covered_by', $metadata);
    }

    public function testKeepsTheVersionFlagsAndCapacityInMetadata(): void
    {
        $metadata = EventVersionMapper::metadata($this->versionRow([
            'max_capacity' => 120,
            'has_book' => 1,
            'has_graduation' => 0,
        ]));

        $this->assertSame(120, $metadata['max_capacity']);
        $this->assertTrue($metadata['has_book']);
        $this->assertFalse($metadata['has_graduation']);
    }

    /**
     * The Eventos tab filters event country and city through the venue
     * (placesareas.places.countries_id), so they are flattened onto the version.
     */
    public function testResolvesEventGeographyThroughTheVenue(): void
    {
        $fields = EventVersionMapper::customFields(
            $this->versionRow(['places_id' => 12]),
            $this->lookupNames(),
            [12 => ['pais' => 'República Dominicana', 'ciudad' => 'Santo Domingo']],
        );

        $this->assertSame('República Dominicana', $fields['pais_evento']);
        $this->assertSame('Santo Domingo', $fields['ciudad_evento']);
    }

    public function testOmitsEventGeographyWhenTheVenueIsUnknown(): void
    {
        $fields = EventVersionMapper::customFields(
            $this->versionRow(['places_id' => 999999]),
            $this->lookupNames(),
            [12 => ['pais' => 'República Dominicana', 'ciudad' => 'Santo Domingo']],
        );

        $this->assertArrayNotHasKey('pais_evento', $fields);
        $this->assertArrayNotHasKey('ciudad_evento', $fields);
    }

    public function testLookupTablesCoversEveryUnmodelledVersionForeignKey(): void
    {
        $this->assertSame(
            ['languages', 'affiliates', 'places', 'places_areas', 'setup_types'],
            EventVersionMapper::lookupTables()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lookupNames(): array
    {
        return [
            'languages' => [1 => 'Español'],
            'affiliates' => [5 => 'FranklinCovey'],
            'places' => [12 => 'Hotel Intercontinental'],
            'places_areas' => [30 => 'Salón Caoba'],
            'setup_types' => [2 => 'Escuela'],
        ];
    }

    private function versionRow(array $overrides = []): stdClass
    {
        $defaults = [
            'id' => 1,
            'languages_id' => null,
            'affiliates_id' => null,
            'places_id' => null,
            'places_areas_id' => null,
            'setup_types_id' => null,
            'facilitators_satisfaction' => null,
            'companies_satisfaction' => null,
            'exchange_rate' => null,
            'max_capacity' => null,
            'has_book' => null,
            'has_forum' => null,
            'has_graduation' => null,
            'has_translations' => null,
            'place_covered_by' => null,
            'hotel_covered_by' => null,
            'equip_covered_by' => null,
            'trip_covered_by' => null,
            'flight_covered_by' => null,
        ];

        $row = new stdClass();

        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }
}
