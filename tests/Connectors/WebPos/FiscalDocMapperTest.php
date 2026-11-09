<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Kanvas\Connectors\WebPos\Services\FiscalDocMapperService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden files pin the exact JSON WebPOS receives. A change to one is a change to what DGII sees: read the
 * diff against the v9 field table before regenerating.
 */
final class FiscalDocMapperTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function goldenDocuments(): array
    {
        return [
            'E31 credit invoice, taxed + exempt lines' => ['e31'],
            'E32 anonymous consumer, paid' => ['e32'],
            'E34 credit note in USD' => ['e34'],
        ];
    }

    #[DataProvider('goldenDocuments')]
    public function testTheFiscalDocMatchesTheGoldenFile(string $name): void
    {
        $fiscalDoc = $this->mapper()->map($this->document($name));

        $this->assertJsonStringEqualsJsonFile(
            __DIR__ . "/fixtures/fiscal-doc-{$name}.json",
            (string) json_encode($fiscalDoc),
        );
    }

    public function testSpecialRegimeIsAlwaysExemptAndExportsAlwaysZeroRated(): void
    {
        $e44 = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::REGIMEN_ESPECIAL, EcfItbisRateEnum::RATE_18));
        $e46 = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::EXPORTACION, EcfItbisRateEnum::RATE_18));
        $e31At16 = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::CREDITO_FISCAL, EcfItbisRateEnum::RATE_16));
        $e31At0 = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::CREDITO_FISCAL, EcfItbisRateEnum::RATE_0));

        $this->assertSame(0, $e44['fiscalDoc']['items'][0]['tax']);
        $this->assertSame(3, $e46['fiscalDoc']['items'][0]['tax']);
        $this->assertSame(2, $e31At16['fiscalDoc']['items'][0]['tax']);
        $this->assertSame(3, $e31At0['fiscalDoc']['items'][0]['tax']);
    }

    public function testWithholdingsSetTheRetentionIndicator(): void
    {
        $withheld = $this->mapper()->map($this->singleLineDocument(
            EcfTypeEnum::CREDITO_FISCAL,
            EcfItbisRateEnum::RATE_18,
            itbisWithheld: 54.0,
            isrWithheld: 30.0,
        ));
        $purchase = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::COMPRAS, EcfItbisRateEnum::RATE_18));
        $plain = $this->mapper()->map($this->singleLineDocument(EcfTypeEnum::CREDITO_FISCAL, EcfItbisRateEnum::RATE_18));

        $this->assertSame(1, $withheld['fiscalDoc']['items'][0]['retperInd']);
        $this->assertEquals(54.0, $withheld['fiscalDoc']['items'][0]['retItbis']);
        $this->assertEquals(30.0, $withheld['fiscalDoc']['items'][0]['retIsr']);
        $this->assertSame(1, $purchase['fiscalDoc']['items'][0]['retperInd'], 'E41 always carries retperInd 1.');
        $this->assertArrayNotHasKey('retperInd', $plain['fiscalDoc']['items'][0]);
    }

    public function testAForeignBuyerIsSentAsCustomerType06(): void
    {
        $fiscalDoc = $this->mapper()->map($this->singleLineDocument(
            EcfTypeEnum::CREDITO_FISCAL,
            EcfItbisRateEnum::RATE_18,
            buyer: new EcfParty(name: 'Acme Inc', taxId: 'US-12-3456789', isForeign: true),
        ));

        $this->assertSame('06', $fiscalDoc['fiscalDoc']['customerType']);
        $this->assertSame('US123456789', $fiscalDoc['fiscalDoc']['customerRUC']);
    }

    public function testMoreThanOneHundredItemsIsRefusedBeforeTheCall(): void
    {
        $document = new EcfDocument(
            type: EcfTypeEnum::CREDITO_FISCAL,
            encf: 'E310000000009',
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: 'b3f1c2d4-0000-4000-8000-000000000101',
            buyer: new EcfParty(name: 'Cliente Ejemplo SRL', taxId: '101000001'),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: array_fill(0, 101, $this->line(1, EcfItbisRateEnum::RATE_18)),
            totals: new EcfTotals(
                exempt: 0.0,
                taxed: 30300.0,
                itbis: 5454.0,
                total: 35754.0
            ),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at most 100 items');

        $this->mapper()->map($document);
    }

    public function testAnItemDescriptionLongerThanEightyCharactersIsRefused(): void
    {
        $document = $this->singleLineDocument(EcfTypeEnum::CREDITO_FISCAL, EcfItbisRateEnum::RATE_18, description: str_repeat('x', 81));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('description must be 1 to 80 characters');

        $this->mapper()->map($document);
    }

    public function testAnItemCodeLongerThanThirtyFiveCharactersIsRefused(): void
    {
        $document = $this->singleLineDocument(EcfTypeEnum::CREDITO_FISCAL, EcfItbisRateEnum::RATE_18, code: str_repeat('C', 36));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('item code must be 1 to 35 characters');

        $this->mapper()->map($document);
    }

    private function mapper(): FiscalDocMapperService
    {
        return new FiscalDocMapperService(companyLicense: 'YPSPUFLH', branch: '0001', pos: '001');
    }

    private function document(string $name): EcfDocument
    {
        return match ($name) {
            'e31' => new EcfDocument(
                type: EcfTypeEnum::CREDITO_FISCAL,
                encf: 'E310000000004',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-15',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000031',
                buyer: new EcfParty(
                    name: 'Cliente Ejemplo SRL',
                    taxId: '1-01-00000-1',
                    address: 'Av. Ejemplo 1, Santo Domingo',
                    email: 'ap@cliente.test',
                ),
                currency: 'DOP',
                exchangeRate: 1.0,
                isCredit: true,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'SVC-1',
                        description: 'Consultoria',
                        quantity: 10,
                        unitPrice: 1500.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: true,
                        discountAmount: 0.0,
                        subtotal: 15000.0,
                        tax: 2700.0,
                    ),
                    new EcfLine(
                        lineNumber: 2,
                        code: 'BK-1',
                        description: 'Libro',
                        quantity: 2,
                        unitPrice: 250.0,
                        itbis: EcfItbisRateEnum::EXEMPT,
                        isService: false,
                        discountAmount: 50.0,
                        subtotal: 450.0,
                        tax: 0.0,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 450.0,
                    taxed: 15000.0,
                    itbis: 2700.0,
                    total: 18150.0
                ),
            ),
            'e32' => new EcfDocument(
                type: EcfTypeEnum::CONSUMO,
                encf: 'E320000000001',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-16',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000032',
                buyer: new EcfParty(name: 'Consumidor Final'),
                currency: 'DOP',
                exchangeRate: 1.0,
                isCredit: false,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'CAFE-1',
                        description: 'Cafe molido 1lb',
                        quantity: 3,
                        unitPrice: 412.5,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: false,
                        discountAmount: 0.0,
                        subtotal: 1237.5,
                        tax: 222.75,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 0.0,
                    taxed: 1237.5,
                    itbis: 222.75,
                    total: 1460.25
                ),
            ),
            'e34' => new EcfDocument(
                type: EcfTypeEnum::NOTA_CREDITO,
                encf: 'E340000000001',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-20',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000034',
                buyer: new EcfParty(name: 'Cliente Ejemplo SRL', taxId: '101000001'),
                currency: 'USD',
                exchangeRate: 58.51234,
                isCredit: true,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'SVC-1',
                        description: 'Ajuste consultoria',
                        quantity: 1,
                        unitPrice: 100.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: true,
                        discountAmount: 0.0,
                        subtotal: 100.0,
                        tax: 18.0,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 0.0,
                    taxed: 100.0,
                    itbis: 18.0,
                    total: 118.0
                ),
                reference: new EcfReference(
                    encf: 'E310000000004',
                    issueDate: '2026-06-15',
                    modificationCode: 3,
                    reason: 'Ajuste de precio'
                ),
            ),
        };
    }

    private function singleLineDocument(
        EcfTypeEnum $type,
        EcfItbisRateEnum $itbis,
        float $itbisWithheld = 0.0,
        float $isrWithheld = 0.0,
        ?EcfParty $buyer = null,
        string $description = 'Servicio',
        string $code = 'SVC-1',
    ): EcfDocument {
        return new EcfDocument(
            type: $type,
            encf: $type->series() . '0000000001',
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: 'b3f1c2d4-0000-4000-8000-0000000000' . $type->value,
            buyer: $buyer ?? new EcfParty(name: 'Cliente Ejemplo SRL', taxId: '101000001'),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: [$this->line(
                1,
                $itbis,
                $itbisWithheld,
                $isrWithheld,
                $description,
                $code
            )],
            totals: new EcfTotals(
                exempt: 0.0,
                taxed: 300.0,
                itbis: 54.0,
                total: 354.0
            ),
        );
    }

    private function line(
        int $number,
        EcfItbisRateEnum $itbis,
        float $itbisWithheld = 0.0,
        float $isrWithheld = 0.0,
        string $description = 'Servicio',
        string $code = 'SVC-1',
    ): EcfLine {
        return new EcfLine(
            lineNumber: $number,
            code: $code,
            description: $description,
            quantity: 1,
            unitPrice: 300.0,
            itbis: $itbis,
            isService: true,
            discountAmount: 0.0,
            subtotal: 300.0,
            tax: $itbis->percent() * 3,
            itbisWithheld: $itbisWithheld,
            isrWithheld: $isrWithheld,
        );
    }
}
