<?php

declare(strict_types=1);

namespace Tests\Connectors\Alanube;

use Kanvas\Connectors\Alanube\Mappers\AlanubeDocumentMapper;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Connectors\Traits\HasAlanubeConfiguration;
use Tests\TestCase;

/**
 * Golden bodies: each fixture is the exact JSON Alanube's per-type endpoint receives. A diff here is a change
 * to what reaches DGII, so a fixture is only regenerated deliberately and reviewed.
 */
final class AlanubeDocumentMapperTest extends TestCase
{
    use HasAlanubeConfiguration;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documents(): array
    {
        return [
            'E31 credit invoice, 18% service + exempt good' => ['e31', 'fiscal-invoices'],
            'E32 anonymous consumer invoice' => ['e32', 'invoices'],
            'E34 credit note over 30 days' => ['e34', 'credit-notes'],
            'E31 in USD, converted to DOP' => ['e31-usd', 'fiscal-invoices'],
        ];
    }

    #[DataProvider('documents')]
    public function testMapsTheDocumentToTheGoldenAlanubeBody(string $fixture, string $endpoint): void
    {
        $document = $this->document($fixture);

        $this->assertSame($endpoint, AlanubeDocumentMapper::endpointFor($document->type));
        $this->assertSame(
            json_decode((string) file_get_contents(__DIR__ . "/fixtures/{$fixture}.json"), true),
            json_decode((string) json_encode(new AlanubeDocumentMapper($this->senderProfile())->map($document), JSON_PRESERVE_ZERO_FRACTION), true),
        );
    }

    public function testACreditNoteWithinThirtyDaysKeepsItsItbisCredit(): void
    {
        $note = $this->document('e34');
        $recent = new EcfDocument(
            type: $note->type,
            encf: $note->encf,
            sequenceExpiresAt: $note->sequenceExpiresAt,
            issueDate: '2026-05-20',
            systemRef: $note->systemRef,
            buyer: $note->buyer,
            currency: $note->currency,
            exchangeRate: $note->exchangeRate,
            isCredit: $note->isCredit,
            lines: $note->lines,
            totals: $note->totals,
            reference: $note->reference,
        );

        $body = new AlanubeDocumentMapper($this->senderProfile())->map($recent);

        $this->assertSame(0, $body['idDoc']['creditNoteIndicator']);
    }

    private function document(string $fixture): EcfDocument
    {
        return match ($fixture) {
            'e31' => new EcfDocument(
                type: EcfTypeEnum::CREDITO_FISCAL,
                encf: 'E310000000004',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-15',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000031',
                buyer: new EcfParty(name: 'Cliente Corporativo SRL', taxId: '101-00000-1', email: 'ap@cliente.example'),
                currency: 'DOP',
                exchangeRate: 1.0,
                isCredit: true,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'SRV-01',
                        description: 'Consultoria de implementacion',
                        quantity: 2.0,
                        unitPrice: 1000.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: true,
                        discountAmount: 0.0,
                        subtotal: 2000.0,
                        tax: 360.0,
                    ),
                    new EcfLine(
                        lineNumber: 2,
                        code: '',
                        description: 'Libro tecnico',
                        quantity: 1.0,
                        unitPrice: 550.0,
                        itbis: EcfItbisRateEnum::EXEMPT,
                        isService: false,
                        discountAmount: 50.0,
                        subtotal: 500.0,
                        tax: 0.0,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 500.0,
                    taxed: 2000.0,
                    itbis: 360.0,
                    total: 2860.0
                ),
            ),
            'e32' => new EcfDocument(
                type: EcfTypeEnum::CONSUMO,
                encf: 'E320000000010',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-15',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000032',
                buyer: new EcfParty(name: 'Consumidor final'),
                currency: 'DOP',
                exchangeRate: 1.0,
                isCredit: false,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'CAM-L',
                        description: 'Camisa negra talla L',
                        quantity: 1.0,
                        unitPrice: 1000.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: false,
                        discountAmount: 0.0,
                        subtotal: 1000.0,
                        tax: 180.0,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 0.0,
                    taxed: 1000.0,
                    itbis: 180.0,
                    total: 1180.0
                ),
            ),
            'e34' => new EcfDocument(
                type: EcfTypeEnum::NOTA_CREDITO,
                encf: 'E340000000002',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-15',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000034',
                buyer: new EcfParty(name: 'Cliente Corporativo SRL', taxId: '101000001'),
                currency: 'DOP',
                exchangeRate: 1.0,
                isCredit: false,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'SRV-01',
                        description: 'Consultoria de implementacion',
                        quantity: 0.5,
                        unitPrice: 1000.0,
                        itbis: EcfItbisRateEnum::RATE_18,
                        isService: true,
                        discountAmount: 0.0,
                        subtotal: 500.0,
                        tax: 90.0,
                    ),
                ],
                totals: new EcfTotals(
                    exempt: 0.0,
                    taxed: 500.0,
                    itbis: 90.0,
                    total: 590.0
                ),
                reference: new EcfReference(
                    encf: 'E310000000004',
                    issueDate: '2026-05-01',
                    modificationCode: 3,
                    reason: 'Horas facturadas de mas',
                ),
            ),
            'e31-usd' => new EcfDocument(
                type: EcfTypeEnum::CREDITO_FISCAL,
                encf: 'E310000000005',
                sequenceExpiresAt: '2028-12-31',
                issueDate: '2026-06-15',
                systemRef: 'b3f1c2d4-0000-4000-8000-000000000035',
                buyer: new EcfParty(name: 'Cliente Corporativo SRL', taxId: '101000001'),
                currency: 'USD',
                exchangeRate: 60.0,
                isCredit: true,
                lines: [
                    new EcfLine(
                        lineNumber: 1,
                        code: 'SRV-02',
                        description: 'Soporte mensual',
                        quantity: 1.0,
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
            ),
        };
    }
}
