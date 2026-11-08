<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Kanvas\Connectors\WebPos\Services\FiscalDocMapperService;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfExport;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfForeignPayment;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Tests\TestCase;

/**
 * The type-specific fields the first mapper had no contract for (Track D open item 5): E45 purchase order,
 * E46 export block, E47 payment details as addInfo, and the E33 debit-note reference.
 */
final class FiscalDocMapperTypeFieldsTest extends TestCase
{
    public function testAGovernmentInvoiceCarriesItsPurchaseOrder(): void
    {
        $fiscalDoc = $this->map($this->document(EcfTypeEnum::GUBERNAMENTAL, purchaseOrderNumber: 'OC-2026-0042'));

        $this->assertSame('OC-2026-0042', $fiscalDoc['fedo']['numeroOrdenCompra']);
        $this->assertArrayNotHasKey('incoterm', $fiscalDoc['fedo']);
    }

    public function testAnExportCarriesIncotermDestinationAndEstimatedValue(): void
    {
        $fiscalDoc = $this->map($this->document(
            EcfTypeEnum::EXPORTACION,
            export: new EcfExport(incoterm: 'FOB', destinationCountry: 'US', estimatedForeignValue: 1999.999),
        ));

        $this->assertSame('FOB', $fiscalDoc['fedo']['incoterm']);
        $this->assertSame('US', $fiscalDoc['fedo']['destCountry']);
        $this->assertSame(2000.0, $fiscalDoc['fedo']['totValEst']);
        $this->assertSame(3, $fiscalDoc['items'][0]['tax']);
    }

    public function testAForeignPaymentGoesOutAsAddInfoAddressedByGroupAndName(): void
    {
        $fiscalDoc = $this->map($this->document(
            EcfTypeEnum::PAGOS_EXTERIOR,
            buyer: new EcfParty(name: 'Acme Ltd', taxId: 'GB123456789', isForeign: true),
            foreignPayment: new EcfForeignPayment(
                accountType: '1',
                accountNumber: '000123',
                bank: 'Banco Popular',
                foreignCurrency: 'USD',
                foreignAmount: 500.0
            ),
        ));

        $this->assertSame('06', $fiscalDoc['customerType']);
        $this->assertSame('GB123456789', $fiscalDoc['customerRUC']);
        $this->assertSame(1, $fiscalDoc['items'][0]['retperInd']);
        $this->assertSame([
            ['id' => 1, 'value' => '1', 'grp' => 'IdDoc', 'name' => 'TipoCuentaPago'],
            ['id' => 2, 'value' => '000123', 'grp' => 'IdDoc', 'name' => 'NumeroCuentaPago'],
            ['id' => 3, 'value' => 'Banco Popular', 'grp' => 'IdDoc', 'name' => 'BancoPago'],
            ['id' => 4, 'value' => 'USD', 'grp' => 'OtraMoneda', 'name' => 'TipoMoneda'],
            ['id' => 5, 'value' => '500.00', 'grp' => 'OtraMoneda', 'name' => 'MontoTotalOtraMoneda'],
        ], $fiscalDoc['addInfo']);
    }

    public function testADebitNoteReferencesTheInvoiceWithCode3(): void
    {
        $fiscalDoc = $this->map($this->document(
            EcfTypeEnum::NOTA_DEBITO,
            reference: new EcfReference(encf: 'E310000000004', issueDate: '2026-06-15', modificationCode: 3),
        ));

        $this->assertSame(33, $fiscalDoc['docType']);
        $this->assertSame('E310000000004', $fiscalDoc['invoiceNumber']);
        $this->assertSame('3', $fiscalDoc['fedo']['codigoModificacion']);
        $this->assertArrayNotHasKey('addInfo', $fiscalDoc);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(EcfDocument $document): array
    {
        return new FiscalDocMapperService('YPSPUFLH', '0001', '001')->map($document)['fiscalDoc'];
    }

    private function document(
        EcfTypeEnum $type,
        ?EcfParty $buyer = null,
        ?string $purchaseOrderNumber = null,
        ?EcfExport $export = null,
        ?EcfForeignPayment $foreignPayment = null,
        ?EcfReference $reference = null,
    ): EcfDocument {
        $itbis = $type === EcfTypeEnum::EXPORTACION ? EcfItbisRateEnum::RATE_0 : EcfItbisRateEnum::RATE_18;
        $tax = $itbis === EcfItbisRateEnum::RATE_18 ? 180.0 : 0.0;

        return new EcfDocument(
            type: $type,
            encf: $type->series() . '0000000001',
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: 'b0b6f6c5-0000-4000-8000-000000000001',
            buyer: $buyer ?? new EcfParty(name: 'Ministerio de Prueba', taxId: '401000000'),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: [
                new EcfLine(
                    lineNumber: 1,
                    code: 'SRV-1',
                    description: 'Servicio',
                    quantity: 1,
                    unitPrice: 1000.0,
                    itbis: $itbis,
                    isService: true,
                    discountAmount: 0.0,
                    subtotal: 1000.0,
                    tax: $tax,
                ),
            ],
            totals: new EcfTotals(
                exempt: $tax > 0 ? 0.0 : 1000.0,
                taxed: $tax > 0 ? 1000.0 : 0.0,
                itbis: $tax,
                total: 1000.0 + $tax
            ),
            reference: $reference,
            purchaseOrderNumber: $purchaseOrderNumber,
            export: $export,
            foreignPayment: $foreignPayment,
        );
    }
}
