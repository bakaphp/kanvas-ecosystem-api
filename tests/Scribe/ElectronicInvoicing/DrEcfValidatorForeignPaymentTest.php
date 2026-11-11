<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfForeignPayment;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrEcfValidator;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Tests\TestCase;

/**
 * DGII observed 1144 (TipoCuentaPago no es válido) on the first E47 sent with a numeric account type; the
 * catalogue is CT, AH, OT.
 */
final class DrEcfValidatorForeignPaymentTest extends TestCase
{
    public function testAnAccountTypeOutsideDgiiCatalogueIsRefusedBeforeANumberIsSpent(): void
    {
        $errors = new DrEcfValidator()->errors($this->e47(new EcfForeignPayment(accountType: '1', accountNumber: '000123', bank: 'Banco Popular')));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("payment account type '1' must be one of CT, AH, OT", $errors[0]);
    }

    public function testCatalogueAccountTypesPassInAnyCase(): void
    {
        foreach (['CT', 'ah', 'Ot'] as $type) {
            $this->assertSame([], new DrEcfValidator()->errors($this->e47(new EcfForeignPayment(accountType: $type))), $type);
        }

        $this->assertSame([], new DrEcfValidator()->errors($this->e47(null)), 'Payment details are optional on an E47.');
    }

    private function e47(?EcfForeignPayment $payment): EcfDocument
    {
        return new EcfDocument(
            type: EcfTypeEnum::PAGOS_EXTERIOR,
            encf: 'E470000000001',
            sequenceExpiresAt: '2028-12-31',
            issueDate: '2026-06-15',
            systemRef: 'KREF000000000047',
            buyer: new EcfParty(name: 'Cloud Services Ltd', taxId: 'GB123456789', isForeign: true),
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: [
                new EcfLine(
                    lineNumber: 1,
                    code: 'SRV-1',
                    description: 'Cloud hosting',
                    quantity: 1,
                    unitPrice: 500.0,
                    itbis: EcfItbisRateEnum::EXEMPT,
                    isService: true,
                    discountAmount: 0.0,
                    subtotal: 500.0,
                    tax: 0.0,
                ),
            ],
            totals: new EcfTotals(
                exempt: 500.0,
                taxed: 0.0,
                itbis: 0.0,
                total: 500.0
            ),
            foreignPayment: $payment,
        );
    }
}
