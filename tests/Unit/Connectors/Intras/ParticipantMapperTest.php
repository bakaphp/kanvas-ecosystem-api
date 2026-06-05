<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

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

    public function testContactFieldNamesExposesEverythingWeBulkLoad(): void
    {
        $names = ParticipantMapper::contactFieldNames();

        foreach (['email_oficina', 'email_personal', 'celular_1', 'telefono_oficina_1', 'telefono_casa', 'ext_1'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    private function participantRow(array $overrides = []): stdClass
    {
        $defaults = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'position' => null,
            'identification' => null,
            'is_prospect' => 0,
            'classification' => null,
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
