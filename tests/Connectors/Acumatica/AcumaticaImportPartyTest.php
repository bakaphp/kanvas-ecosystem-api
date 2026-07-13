<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportParty;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Tests\TestCase;

class AcumaticaImportPartyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AcctCD' => 'ACME-RETAIL',
            'AcctName' => 'ACME Retail Inc',
            'NoteID' => 'AA11BB22-CCCC-DDDD-EEEE-FF0011223344',
            'FirstName' => 'Jane',
            'LastName' => 'Buyer',
            'EMail' => 'buyer@acme.test',
            'Phone1' => '+1-800-555-0100',
            'AddressLine1' => '1 Market St',
            'City' => 'Springfield',
            'State' => 'OH',
            'CountryID' => 'US',
            'PostalCode' => '43026',
        ], $overrides);
    }

    public function testMapsCustomerWithContactAndAddress(): void
    {
        $party = AcumaticaImportParty::from($this->row());

        $this->assertSame('Jane', $party->firstname);
        $this->assertSame('Buyer', $party->lastname);
        $this->assertSame('ACME Retail Inc', $party->organization);
        $this->assertSame('buyer@acme.test', $party->email);
        $this->assertSame('+1-800-555-0100', $party->phone);
        $this->assertSame('ACME-RETAIL', $party->sourceId);
        $this->assertSame('ACME-RETAIL', $party->customFields[CustomFieldEnum::CUSTOMER_ID->value]);
        $this->assertArrayHasKey(CustomFieldEnum::NOTE_ID->value, $party->customFields);

        $this->assertSame('1 Market St', $party->address?->address);
        $this->assertSame('OH', $party->address?->state);
        $this->assertSame('US', $party->address?->country);
    }

    public function testVendorUsesVendorCustomFieldKey(): void
    {
        $party = AcumaticaImportParty::from($this->row(['AcctCD' => 'ACME-SUPPLY']), true);

        $this->assertSame('ACME-SUPPLY', $party->customFields[CustomFieldEnum::VENDOR_ID->value]);
        $this->assertArrayNotHasKey(CustomFieldEnum::CUSTOMER_ID->value, $party->customFields);
    }

    public function testFirstnameFallsBackToAccountNameWhenNoContact(): void
    {
        $party = AcumaticaImportParty::from($this->row(['FirstName' => '', 'LastName' => '']));

        $this->assertSame('ACME Retail Inc', $party->firstname);
        $this->assertNull($party->lastname);
    }

    public function testNoAddressWhenLine1Missing(): void
    {
        $party = AcumaticaImportParty::from($this->row(['AddressLine1' => '']));

        $this->assertNull($party->address);
    }
}
