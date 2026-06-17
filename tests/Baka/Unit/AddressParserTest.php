<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\AddressParser;
use Tests\TestCase;

class AddressParserTest extends TestCase
{
    public function testParsesCommaBeforeZip(): void
    {
        // Regression: Intellicheck OCR emits a comma between state and zip
        // ("IN, 46410"), which the old parsers dropped (lead 702409).
        $this->assertSame(
            [
                'address' => '2503 W LINCOLN HWY',
                'city' => 'MERRILLVILLE',
                'state' => 'IN',
                'zipcode' => '46410',
            ],
            AddressParser::parse('2503 W LINCOLN HWY, MERRILLVILLE, IN, 46410')
        );
    }

    public function testParsesStandardCommaForm(): void
    {
        $this->assertSame(
            [
                'address' => '789 Pine Rd',
                'city' => 'Austin',
                'state' => 'TX',
                'zipcode' => '78701',
            ],
            AddressParser::parse('789 Pine Rd, Austin, TX 78701')
        );
    }

    public function testParsesWithoutAnyCommas(): void
    {
        $this->assertSame(
            [
                'address' => '2503 W LINCOLN HWY',
                'city' => 'MERRILLVILLE',
                'state' => 'IN',
                'zipcode' => '46410',
            ],
            AddressParser::parse('2503 W LINCOLN HWY MERRILLVILLE IN 46410')
        );
    }

    public function testParsesWithoutSpacesAroundCommas(): void
    {
        $this->assertSame(
            [
                'address' => '789 ELM ST',
                'city' => 'DALLAS',
                'state' => 'TX',
                'zipcode' => '75001',
            ],
            AddressParser::parse('789 ELM ST,DALLAS,TX,75001')
        );
    }

    public function testParsesMultilineForm(): void
    {
        $this->assertSame(
            [
                'address' => '123 Main St',
                'city' => 'Miami',
                'state' => 'FL',
                'zipcode' => '33101',
            ],
            AddressParser::parse("123 Main St\nMiami, FL 33101")
        );
    }

    public function testKeepsUnitLineOnStreet(): void
    {
        $result = AddressParser::parse('456 OAK AVE, APT 5, AUSTIN, TX 78701-1234');

        $this->assertSame('456 OAK AVE, APT 5', $result['address']);
        $this->assertSame('AUSTIN', $result['city']);
        $this->assertSame('TX', $result['state']);
        $this->assertSame('78701-1234', $result['zipcode']);
    }

    public function testReturnsNullWhenUnparseable(): void
    {
        $this->assertNull(AddressParser::parse('totally not an address'));
    }
}
