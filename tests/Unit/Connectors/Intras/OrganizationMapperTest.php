<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

class OrganizationMapperTest extends TestCase
{
    public function testResolvesEveryCompanyCatalogToItsReadableName(): void
    {
        $mapped = OrganizationMapper::fromIntras(
            $this->companyRow([
                'business_sectors_id' => 3,
                'business_types_id' => 1,
                'business_activities_id' => 9,
                'companies_statuses_id' => 2,
            ]),
            [],
            $this->lookupNames(),
        );

        $this->assertSame('Financiero', $mapped['custom_fields']['sector']);
        $this->assertSame('Privada', $mapped['custom_fields']['tipo']);
        $this->assertSame('Banca múltiple', $mapped['custom_fields']['actividad']);
        $this->assertSame('ACTIVA', $mapped['custom_fields']['estatus']);
    }

    public function testStoresTamanoFromTheLegacyCustomFieldTable(): void
    {
        $mapped = OrganizationMapper::fromIntras(
            $this->companyRow(),
            ['tamano' => 'grande', 'telefono' => '809-555-0100'],
            $this->lookupNames(),
        );

        $this->assertSame('grande', $mapped['custom_fields']['tamano']);
        $this->assertSame('809-555-0100', $mapped['custom_fields']['telefono']);
    }

    /**
     * "Vinculación Empresarial" is a self-FK. The legacy id is meaningless outside SIPGO, so the
     * parent's name is stored instead.
     */
    public function testResolvesTheLinkedCompanyToItsName(): void
    {
        $mapped = OrganizationMapper::fromIntras(
            $this->companyRow(['linked_companies_id' => 77]),
            [],
            $this->lookupNames(),
            [77 => 'Grupo Popular'],
        );

        $this->assertSame('Grupo Popular', $mapped['custom_fields']['vinculacion']);
    }

    public function testTreatsAZeroLinkedCompanyAsNoLink(): void
    {
        $mapped = OrganizationMapper::fromIntras(
            $this->companyRow(['linked_companies_id' => 0]),
            [],
            $this->lookupNames(),
            [77 => 'Grupo Popular'],
        );

        $this->assertArrayNotHasKey('vinculacion', $mapped['custom_fields']);
    }

    /**
     * Same trap as the participant mapper: array_filter's default callback drops false and 0, so
     * a client company and a company with zero won quotes both read as "never imported".
     */
    public function testKeepsFalseAndZeroValuedFields(): void
    {
        $mapped = OrganizationMapper::fromIntras($this->companyRow([
            'is_prospect' => 0,
            'is_supplier' => 0,
            'quotes_count' => 0,
            'quotes_won' => 0,
        ]));

        $this->assertFalse($mapped['custom_fields']['intras_is_prospect']);
        $this->assertFalse($mapped['custom_fields']['es_suplidor']);
        $this->assertSame(0, $mapped['custom_fields']['cotizaciones_total']);
        $this->assertSame(0, $mapped['custom_fields']['cotizaciones_ganadas']);
    }

    public function testKeepsTheTaxIdAndHeadcountTheExportNeeds(): void
    {
        $mapped = OrganizationMapper::fromIntras($this->companyRow([
            'rnc' => '101010101',
            'total_employees' => 450,
        ]));

        $this->assertSame('101010101', $mapped['custom_fields']['rnc']);
        $this->assertSame(450, $mapped['custom_fields']['total_employees']);
    }

    public function testSkipsACatalogFieldWhenTheRowCannotBeResolved(): void
    {
        $mapped = OrganizationMapper::fromIntras(
            $this->companyRow(['business_sectors_id' => 999999]),
            [],
            $this->lookupNames(),
        );

        $this->assertArrayNotHasKey('sector', $mapped['custom_fields']);
    }

    public function testLookupTablesCoversEveryCompanyForeignKey(): void
    {
        $this->assertSame(
            ['business_sectors', 'business_types', 'business_activities', 'companies_statuses'],
            OrganizationMapper::lookupTables()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lookupNames(): array
    {
        return [
            'business_sectors' => [3 => 'Financiero'],
            'business_types' => [1 => 'Privada'],
            'business_activities' => [9 => 'Banca múltiple'],
            'companies_statuses' => [2 => 'ACTIVA'],
        ];
    }

    private function companyRow(array $overrides = []): stdClass
    {
        $defaults = [
            'id' => 1,
            'name' => 'Banesco República Dominicana',
            'rnc' => null,
            'classification' => null,
            'classification_open' => null,
            'potentiality' => null,
            'is_prospect' => 0,
            'is_supplier' => null,
            'total_employees' => null,
            'quotes_count' => null,
            'quotes_won' => null,
            'quotes_investment_range' => null,
            'linked_companies_id' => null,
            'business_sectors_id' => null,
            'business_types_id' => null,
            'business_activities_id' => null,
            'companies_statuses_id' => null,
        ];

        $row = new stdClass();

        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }
}
