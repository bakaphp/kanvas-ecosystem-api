<?php

declare(strict_types=1);

namespace Tests\Guild\Unit;

use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmPhones;
use PHPUnit\Framework\TestCase;

final class CrmCustomerTest extends TestCase
{
    public function testNamesAreComparedWithoutCaseOrExtraSpaces(): void
    {
        $customer = new CrmCustomer(firstname: '  ANA   MARIA ', lastname: 'Perez');

        $this->assertSame('ana maria', $customer->normalizedFirstname());
        $this->assertSame('perez', $customer->normalizedLastname());
        $this->assertTrue($customer->hasBothNames());
    }

    public function testAccentsAreKeptWhenLowercasing(): void
    {
        $this->assertSame('josé', CrmCustomer::normalizeName('JOSÉ'));
    }

    public function testABlankNameIsAbsent(): void
    {
        $customer = new CrmCustomer(firstname: 'Ana', lastname: '   ');

        $this->assertNull($customer->normalizedLastname());
        $this->assertFalse($customer->hasBothNames());
        $this->assertFalse(new CrmCustomer()->hasBothNames());
    }

    public function testEmailsAreLowercasedTrimmedAndDeduplicated(): void
    {
        $customer = new CrmCustomer(emails: [' Ana@Example.com ', 'ana@example.com', '', 'other@example.com']);

        $this->assertSame(['ana@example.com', 'other@example.com'], $customer->normalizedEmails());
        $this->assertSame([], new CrmCustomer()->normalizedEmails());
    }

    public function testPhonesAreDigitsOnlyWithoutBlanksOrRepeats(): void
    {
        $customer = new CrmCustomer(phones: new CrmPhones(cell: '(787) 555-0101', home: '7875550101', work: null));

        $this->assertSame(['7875550101'], $customer->phoneNumbers());
        $this->assertSame([], new CrmCustomer()->phoneNumbers());
    }
}
