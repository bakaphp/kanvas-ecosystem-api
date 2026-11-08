<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\ReceivedEcfXmlParserService;
use Tests\TestCase;

final class ReceivedEcfXmlParserTest extends TestCase
{
    public function testParsesVendorDocumentLinesAndReconcilesItbisToTheDocumentTotal(): void
    {
        $received = new ReceivedEcfXmlParserService()->parse(
            (string) file_get_contents(__DIR__ . '/fixtures/received-e31.xml'),
            EcfProviderEnum::WEBPOS,
            'https://ecf.dgii.gov.do/ConsultaTimbre?ENCF=E310000000123&CodigoSeguridad=Zz9Yy8',
        );

        $this->assertSame('webpos', $received->provider);
        $this->assertSame('101000009', $received->vendorTaxId);
        $this->assertSame('Proveedora Ejemplo SRL', $received->vendorName);
        $this->assertSame('E310000000123', $received->encf);
        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, $received->type);
        $this->assertSame('2026-06-10', $received->issueDate);
        $this->assertSame('2026-07-15', $received->dueDate);
        $this->assertSame('DOP', $received->currency);
        $this->assertSame('Zz9Yy8', $received->securityCode);
        $this->assertSame('10-06-2026 10:00:00', $received->signedAt);

        // Line 5 is "no facturable": informational, outside the totals, never a bill line.
        $this->assertCount(4, $received->lines);
        [$support, $license, $equipment, $book] = $received->lines;

        $this->assertTrue($support->isService);
        $this->assertFalse($license->isService);
        $this->assertSame(EcfItbisRateEnum::EXEMPT, $book->itbis);
        $this->assertSame(0.0, $book->tax);
        $this->assertEqualsWithDelta(66.70, $equipment->discountAmount, 0.001);
        $this->assertEqualsWithDelta(833.30, $equipment->amount, 0.001);

        // 60.00 + 60.00 + 149.99 rounds to 269.99; the vendor says 270.00, so the cent lands on the largest line.
        $this->assertEqualsWithDelta(150.00, $equipment->tax, 0.001);
        $this->assertEqualsWithDelta(270.00, array_sum(array_map(fn ($line) => $line->tax, $received->lines)), 0.001);
        $this->assertEqualsWithDelta(1970.00, $received->totals->total, 0.001);
    }

    public function testAForeignCurrencyDocumentIsBookedInThatCurrency(): void
    {
        $received = new ReceivedEcfXmlParserService()->parse(
            (string) file_get_contents(__DIR__ . '/fixtures/received-e31-usd.xml'),
            EcfProviderEnum::ALANUBE,
        );

        $this->assertSame('alanube', $received->provider);

        $this->assertSame('USD', $received->currency);
        $this->assertSame(58.5, $received->exchangeRate);
        $this->assertEqualsWithDelta(100.0, $received->lines[0]->amount, 0.001);
        $this->assertEqualsWithDelta(18.0, $received->lines[0]->tax, 0.001);
        $this->assertEqualsWithDelta(118.0, $received->totals->total, 0.001);
    }

    public function testInvalidXmlIsRefusedClearly(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not valid XML');

        new ReceivedEcfXmlParserService()->parse('<ECF><unclosed>', EcfProviderEnum::WEBPOS);
    }
}
