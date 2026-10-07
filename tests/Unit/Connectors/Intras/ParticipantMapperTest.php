<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use PHPUnit\Framework\TestCase;
use stdClass;

class ParticipantMapperTest extends TestCase
{
    public function testMapsBaseFieldsAndCustomFieldsFromParticipantRow(): void
    {
        $row = $this->participantRow([
            'first_name' => ' Maria ',
            'last_name' => ' Perez ',
            'position' => 'CEO',
            'identification' => '00112233445',
            'is_prospect' => 0,
            'classification' => 'A',
        ]);

        $mapped = ParticipantMapper::fromIntras($row);

        $this->assertSame('Maria', $mapped['firstname']);
        $this->assertSame('Perez', $mapped['lastname']);
        $this->assertSame('CEO', $mapped['custom_fields']['position']);
        $this->assertSame('00112233445', $mapped['custom_fields']['identification']);
        $this->assertSame('A', $mapped['custom_fields']['intras_classification']);
        $this->assertSame([], $mapped['contacts']);
    }

    public function testExtractsContactsFromBulkLoadedCustomFieldRows(): void
    {
        $row = $this->participantRow();
        $contactRows = [
            'email_oficina' => 'maria@intras.com.do',
            'email_personal' => 'maria@gmail.com',
            'celular_1' => '+1 809 555 0100',
            'celular_2' => '8095550101',
            'telefono_oficina_1' => '8095550200',
            'telefono_casa' => '8095550300',
        ];

        $contacts = ParticipantMapper::fromIntras($row, $contactRows)['contacts'];

        $this->assertCount(6, $contacts);

        $email = $this->find($contacts, ContactTypeEnum::EMAIL);
        $this->assertSame('maria@intras.com.do', $email['value']);
        $this->assertSame(0, $email['weight']);

        $secondary = $this->find($contacts, ContactTypeEnum::SECONDARY_EMAIL);
        $this->assertSame('maria@gmail.com', $secondary['value']);

        $cellphones = array_values(array_filter($contacts, fn ($c) => $c['type'] === ContactTypeEnum::CELLPHONE));
        $this->assertSame('+1 809 555 0100', $cellphones[0]['value']);
        $this->assertSame(0, $cellphones[0]['weight']);
        $this->assertSame('8095550101', $cellphones[1]['value']);
        $this->assertSame(1, $cellphones[1]['weight']);
    }

    public function testSkipsEmptyAndWhitespaceOnlyContactValues(): void
    {
        $row = $this->participantRow();
        $contactRows = [
            'email_oficina' => '',
            'email_personal' => '   ',
            'celular_1' => 'real@value',
        ];

        $contacts = ParticipantMapper::fromIntras($row, $contactRows)['contacts'];

        $this->assertCount(1, $contacts);
        $this->assertSame(ContactTypeEnum::CELLPHONE, $contacts[0]['type']);
    }

    public function testStoresExtensionsAsPeopleCustomFieldsNotContacts(): void
    {
        $row = $this->participantRow();
        $contactRows = [
            'ext_1' => '101',
            'ext_2' => '102',
        ];

        $mapped = ParticipantMapper::fromIntras($row, $contactRows);

        $this->assertSame('101', $mapped['custom_fields']['intras_ext_1']);
        $this->assertSame('102', $mapped['custom_fields']['intras_ext_2']);
        $this->assertSame([], $mapped['contacts']);
    }

    public function testResolvesNivelAndAreaThroughTheirLookupCatalogs(): void
    {
        $row = $this->participantRow([
            'participants_levels_id' => 120151,
            'themes_areas_id' => 8,
        ]);

        $mapped = ParticipantMapper::fromIntras($row, [], $this->lookupNames());

        $this->assertSame('Gerencia media', $mapped['custom_fields']['nivel']);
        $this->assertSame('Recursos Humanos', $mapped['custom_fields']['area']);
    }

    /**
     * `departments_id` is named like an FK but is VARCHAR(254) free text. It used to be resolved
     * through the `departments` catalog, where the (int) cast turned every value into 0 and the
     * field was silently never set.
     */
    public function testStoresDepartmentAsFreeTextRatherThanResolvingALookupId(): void
    {
        $row = $this->participantRow(['departments_id' => ' Capital Humano ']);

        $mapped = ParticipantMapper::fromIntras($row, [], $this->lookupNames());

        $this->assertSame('Capital Humano', $mapped['custom_fields']['department']);
    }

    public function testTreatsTheLegacyWhitespaceDepartmentPlaceholderAsAbsent(): void
    {
        // SIPGO's beforeSave() writes a single space when the field is empty.
        $mapped = ParticipantMapper::fromIntras($this->participantRow(['departments_id' => ' ']));

        $this->assertArrayNotHasKey('department', $mapped['custom_fields']);
    }

    public function testExposesTheLegacyParticipantIdAsASearchablePaCode(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow(['id' => 40123]));

        $this->assertSame('40123', $mapped['custom_fields']['pa_code']);
    }

    /**
     * array_filter's default callback drops false alongside null, which erased
     * `intras_is_prospect => false` — the CLIENTE half of the Relación Comercial filter, leaving
     * it indistinguishable from "never imported".
     */
    public function testKeepsFalseValuedCustomFieldsSoClienteSurvives(): void
    {
        $client = ParticipantMapper::fromIntras($this->participantRow(['is_prospect' => 0]));
        $prospect = ParticipantMapper::fromIntras($this->participantRow(['is_prospect' => 1]));

        $this->assertArrayHasKey('intras_is_prospect', $client['custom_fields']);
        $this->assertFalse($client['custom_fields']['intras_is_prospect']);
        $this->assertTrue($prospect['custom_fields']['intras_is_prospect']);
    }

    public function testSkipsProfileFieldWhenTheLookupRowIsMissingOrUnnamed(): void
    {
        $row = $this->participantRow([
            'participants_levels_id' => 999999,
            'themes_areas_id' => 8,
        ]);

        $mapped = ParticipantMapper::fromIntras($row, [], $this->lookupNames());

        $this->assertArrayNotHasKey('nivel', $mapped['custom_fields']);
        $this->assertSame('Recursos Humanos', $mapped['custom_fields']['area']);
    }

    public function testStoresNoProfileFieldsWhenTheParticipantHasNoLookupIds(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow(), [], $this->lookupNames());

        $this->assertArrayNotHasKey('nivel', $mapped['custom_fields']);
        $this->assertArrayNotHasKey('area', $mapped['custom_fields']);
        $this->assertArrayNotHasKey('department', $mapped['custom_fields']);
    }

    public function testLookupTablesCoversEveryProfileForeignKey(): void
    {
        // `departments` is deliberately absent — see testStoresDepartmentAsFreeText...
        $this->assertSame(
            ['participants_levels', 'themes_areas', 'participants_statuses', 'professions', 'gifts'],
            ParticipantMapper::lookupTables()
        );
    }

    public function testResolvesEstatusProfesionAndTipoDeRegaloThroughTheirCatalogs(): void
    {
        $row = $this->participantRow([
            'participants_statuses_id' => 2,
            'professions_id' => 44,
            'gifts_id' => 7,
        ]);

        $mapped = ParticipantMapper::fromIntras($row, [], $this->lookupNames());

        $this->assertSame('ACTIVO', $mapped['custom_fields']['estatus']);
        $this->assertSame('Ingeniero', $mapped['custom_fields']['profesion']);
        $this->assertSame('Botella de vino', $mapped['custom_fields']['tipo_regalo']);
    }

    public function testMapsTheScalarProfileAttributesTheGestorFiltersOn(): void
    {
        $row = $this->participantRow([
            'potentiality' => 'ALTA',
            'events_satisfaction' => 4.5,
            'conference_discount' => 15,
        ]);

        $mapped = ParticipantMapper::fromIntras($row);

        $this->assertSame('ALTA', $mapped['custom_fields']['potencialidad']);
        $this->assertSame(4.5, $mapped['custom_fields']['satisfaccion']);
        $this->assertSame(15, $mapped['custom_fields']['descuento']);
    }

    /**
     * Both are checkbox filters, so false must survive array_filter — same trap as
     * intras_is_prospect. A dropped false reads as "never imported" and the exclude-variants of
     * these filters ("Excluir Contacto Clave Axis") stop working.
     */
    public function testKeepsTheAxisAndRepresentativeFlagsWhenFalse(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow([
            'is_axis_participant' => 0,
            'general_representative' => 0,
        ]));

        $this->assertFalse($mapped['custom_fields']['contacto_clave_axis']);
        $this->assertFalse($mapped['custom_fields']['representante_general']);
    }

    public function testLeavesTheFlagsUnsetWhenTheLegacyColumnIsAbsentRatherThanFalse(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow());

        $this->assertArrayNotHasKey('contacto_clave_axis', $mapped['custom_fields']);
        $this->assertArrayNotHasKey('representante_general', $mapped['custom_fields']);
    }

    public function testReturnsDobAsAPeopleColumnNotACustomField(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow(['dob' => '1984-07-19']));

        $this->assertSame('1984-07-19', $mapped['dob']);
        $this->assertArrayNotHasKey('dob', $mapped['custom_fields']);
    }

    /**
     * SIPGO stores the birthday month/day as their own custom fields rather than deriving them
     * from dob, and the Gestor filters on the stored values — so they are imported as given.
     */
    public function testImportsTheStoredBirthdayMonthAndDayRatherThanDerivingThem(): void
    {
        $mapped = ParticipantMapper::fromIntras(
            $this->participantRow(['dob' => '1984-07-19']),
            ['dobmoth' => '7', 'dobdate' => '19'],
        );

        $this->assertSame('7', $mapped['custom_fields']['dob_mes']);
        $this->assertSame('19', $mapped['custom_fields']['dob_dia']);
    }

    public function testKeepsTheConferenceCodeSeparateFromThePaCode(): void
    {
        $mapped = ParticipantMapper::fromIntras(
            $this->participantRow(['id' => 512]),
            ['tccode' => 'TC-900', 'tcporcent' => '20'],
        );

        $this->assertSame('512', $mapped['custom_fields']['pa_code']);
        $this->assertSame('TC-900', $mapped['custom_fields']['tc_code']);
        $this->assertSame('20', $mapped['custom_fields']['tc_porcentaje']);
    }

    public function testMapsGenderAndTheCertificateNameParts(): void
    {
        $mapped = ParticipantMapper::fromIntras($this->participantRow(), [
            'sexo' => 'f',
            'certificado_primer_nombre' => 'Laura',
            'certificado_segundo_apellido' => 'Hache',
        ]);

        $this->assertSame('f', $mapped['custom_fields']['sexo']);
        $this->assertSame('Laura', $mapped['custom_fields']['certificado_primer_nombre']);
        $this->assertSame('Hache', $mapped['custom_fields']['certificado_segundo_apellido']);
    }

    public function testContactFieldNamesExposesEverythingWeBulkLoad(): void
    {
        $names = ParticipantMapper::contactFieldNames();

        foreach (['email_oficina', 'email_personal', 'celular_1', 'telefono_oficina_1', 'telefono_casa', 'ext_1'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testFlattensTheOfficeIntoTheOnlyAddressSipgoHas(): void
    {
        $office = new stdClass();
        $office->address = ' Av. Winston Churchill 1099 ';
        $office->countries_id = 61;
        $office->cities_id = 302;
        $office->districts_id = 14;

        $address = ParticipantMapper::addressFromOffice($office, $this->lookupNames());

        $this->assertSame('Av. Winston Churchill 1099', $address['address']);
        $this->assertSame('República Dominicana', $address['country']);
        $this->assertSame('Santo Domingo', $address['city']);
        $this->assertSame('Piantini', $address['sector']);
    }

    public function testReturnsNoAddressWhenTheParticipantHasNoOffice(): void
    {
        $this->assertNull(ParticipantMapper::addressFromOffice(null, $this->lookupNames()));
    }

    public function testReturnsNoAddressWhenTheOfficeRowIsEntirelyEmpty(): void
    {
        $office = new stdClass();
        $office->address = '   ';
        $office->countries_id = null;
        $office->cities_id = null;
        $office->districts_id = null;

        $this->assertNull(ParticipantMapper::addressFromOffice($office, $this->lookupNames()));
    }

    public function testKeepsAPartialOfficeRatherThanDiscardingIt(): void
    {
        $office = new stdClass();
        $office->address = 'Calle El Sol 45';
        $office->countries_id = null;
        $office->cities_id = 999999;
        $office->districts_id = null;

        $address = ParticipantMapper::addressFromOffice($office, $this->lookupNames());

        $this->assertSame('Calle El Sol 45', $address['address']);
        $this->assertNull($address['city'], 'an unresolvable catalog id stores nothing, not the id');
        $this->assertNull($address['country']);
    }

    public function testCollapsesGroupAndInterestRowsIntoAUniqueNameListPerParticipant(): void
    {
        $rows = [
            $this->namedRow(10, 'Directores RRHH'),
            $this->namedRow(10, 'CEOs'),
            $this->namedRow(10, 'Directores RRHH'),
            $this->namedRow(11, '  Gerentes  '),
            $this->namedRow(11, '   '),
            $this->namedRow(12, null),
        ];

        $names = PullParticipantsFromIntrasAction::groupNamesByParticipant($rows);

        $this->assertSame(['Directores RRHH', 'CEOs'], $names[10], 'duplicates collapse');
        $this->assertSame(['Gerentes'], $names[11], 'names are trimmed, blanks dropped');
        $this->assertArrayNotHasKey(12, $names, 'a null name contributes nothing');
    }

    public function testOfficeLookupTablesCoversEveryOfficeForeignKey(): void
    {
        $this->assertSame(
            ['countries', 'cities', 'districts'],
            ParticipantMapper::officeLookupTables()
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function lookupNames(): array
    {
        return [
            'participants_levels' => [120151 => 'Gerencia media'],
            'themes_areas' => [8 => 'Recursos Humanos'],
            'participants_statuses' => [2 => 'ACTIVO'],
            'professions' => [44 => 'Ingeniero'],
            'gifts' => [7 => 'Botella de vino'],
            'departments' => [3 => 'Capital Humano'],
            'countries' => [61 => 'República Dominicana'],
            'cities' => [302 => 'Santo Domingo'],
            'districts' => [14 => 'Piantini'],
        ];
    }

    private function participantRow(array $overrides = []): stdClass
    {
        $defaults = [
            'id' => 1,
            'first_name' => 'Test',
            'last_name' => 'User',
            'position' => null,
            'identification' => null,
            'is_prospect' => 0,
            'classification' => null,
            'participants_levels_id' => null,
            'themes_areas_id' => null,
            'departments_id' => null,
            'participants_statuses_id' => null,
            'professions_id' => null,
            'gifts_id' => null,
            'potentiality' => null,
            'events_satisfaction' => null,
            'conference_discount' => null,
            'dob' => null,
        ];

        $row = new stdClass();
        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }

    private function namedRow(int $participantId, ?string $name): stdClass
    {
        $row = new stdClass();
        $row->participants_id = $participantId;
        $row->name = $name;

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
