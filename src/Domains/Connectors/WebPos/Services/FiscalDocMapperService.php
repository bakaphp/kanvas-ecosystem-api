<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Services;

use Baka\Support\Money;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrEcfValidator;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;

/**
 * EcfDocument → WebPOS JSON `fiscalDoc` (DR spec v9). Mapping and WebPOS's own limits only: which type to
 * issue, the ITBIS and the totals are decided by EcfDocumentBuilder and sent as given, so WebPOS never
 * recomputes a cent away from the journal entry.
 *
 * DGII's rules (buyer RNC, line count, totals, note reference) are DrEcfValidator's and already ran before
 * the number was assigned. Only WebPOS's own field limits are checked here, before the call: a document we
 * refuse never left, one WebPOS refuses after numbering may have spent its e-NCF.
 */
class FiscalDocMapperService
{
    private const int MAX_SYSTEM_REF = 50;
    private const int MAX_CUSTOMER_NAME = 150;
    private const int MAX_ITEM_CODE = 35;
    private const int MAX_ITEM_DESCRIPTION = 80;

    private const string TAX_MODE_EXCLUDED = '0';
    private const string OPERATION_MODE_OPERATIONS = '01';
    private const string PAYMENT_TYPE_CASH = '1';
    private const string PAYMENT_TYPE_CREDIT = '2';
    private const string CUSTOMER_TYPE_FOREIGN = '06';
    private const string PAYMENT_FORM_TRANSFER = '02';

    public function __construct(
        private readonly string $companyLicense,
        private readonly string $branch,
        private readonly string $pos,
    ) {
    }

    /**
     * @return array{fiscalDoc: array<string, mixed>}
     */
    public function map(EcfDocument $document): array
    {
        $this->assertDocumentLimits($document);

        $fiscalDoc = [
            'companyLicCod' => $this->companyLicense,
            'branchCod' => $this->branch,
            'posCod' => $this->pos,
            'docType' => (int) $document->type->value,
            'docNumber' => $document->encf,
            'docNumberDueDate' => $document->sequenceExpiresAt,
            'systemRef' => $document->systemRef,
            'docDate' => $document->issueDate,
            'currency' => $document->currency,
        ];

        if (strtoupper($document->currency) !== 'DOP') {
            $fiscalDoc['currencyRate'] = round($document->exchangeRate, 4);
        }

        $fiscalDoc += $this->customer($document);

        if ($document->reference !== null) {
            $fiscalDoc['invoiceNumber'] = $document->reference->encf;
            $fiscalDoc['invoiceNumberRefDate'] = $document->reference->issueDate;
        }

        $fiscalDoc['fedo'] = $this->fedo($document);

        $addInfo = $this->foreignPaymentInfo($document);
        if ($addInfo !== []) {
            $fiscalDoc['addInfo'] = $addInfo;
        }
        $fiscalDoc['items'] = array_map(
            fn (EcfLine $line): array => $this->item($document->type, $line),
            array_values($document->lines),
        );

        // An empty payments list is how WebPOS knows the invoice is on credit (prints as CRÉDITO).
        $fiscalDoc['payments'] = $document->isCredit
            ? []
            : [['type' => self::PAYMENT_FORM_TRANSFER, 'amt' => $this->money($document->totals->total)]];

        // v9: `fiscalDoc` is the root object of the request body.
        return ['fiscalDoc' => $fiscalDoc];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(EcfDocument $document): array
    {
        $buyer = $document->buyer;
        $taxId = $buyer->taxId !== null ? (string) preg_replace('/[^0-9A-Za-z]/', '', $buyer->taxId) : '';

        if ($document->type === EcfTypeEnum::GASTOS_MENORES && $taxId === '') {
            return [];
        }

        $customer = ['customerName' => $buyer->name];

        if ($taxId !== '') {
            $customer['customerRUC'] = $taxId;
        }

        if ($buyer->isForeign) {
            $customer['customerType'] = self::CUSTOMER_TYPE_FOREIGN;
        }

        if ($buyer->address !== null && $buyer->address !== '') {
            $customer['customerAddress'] = $buyer->address;
        }

        if ($buyer->email !== null && $buyer->email !== '') {
            $customer['email'] = $buyer->email;
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function fedo(EcfDocument $document): array
    {
        $fedo = [
            'taxMode' => self::TAX_MODE_EXCLUDED,
            'operMode' => self::OPERATION_MODE_OPERATIONS,
            'paymentType' => $document->isCredit ? self::PAYMENT_TYPE_CREDIT : self::PAYMENT_TYPE_CASH,
            'totales' => [
                'subtotalExento' => $this->money($document->totals->exempt),
                'subtotalGravado' => $this->money($document->totals->taxed),
                'totalItbis' => $this->money($document->totals->itbis),
                'subtotalNet' => Money::cents(Money::sum($document->totals->exempt, $document->totals->taxed)),
                'total' => $this->money($document->totals->total),
            ],
        ];

        if ($document->reference !== null) {
            $fedo['codigoModificacion'] = (string) $document->reference->modificationCode;
        }

        if ($document->purchaseOrderNumber !== null) {
            $fedo['numeroOrdenCompra'] = $document->purchaseOrderNumber;
        }

        if ($document->export !== null) {
            $fedo['incoterm'] = $document->export->incoterm;
            $fedo['destCountry'] = $document->export->destinationCountry;

            if ($document->export->estimatedForeignValue !== null) {
                $fedo['totValEst'] = $this->money($document->export->estimatedForeignValue);
            }
        }

        return $fedo;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(EcfTypeEnum $type, EcfLine $line): array
    {
        $item = [
            'qty' => round($line->quantity, 2),
            'price' => round($line->unitPrice, 4),
            'code' => $line->code,
            'desc' => $line->description,
            'itemClass' => $line->isService ? 1 : 0,
            'tax' => $this->taxCode($type, $line->itbis),
            'subTotal' => $this->money($line->subtotal),
            'taxTotal' => $this->money($line->tax),
        ];

        if ($line->discountAmount > 0) {
            $item['damt'] = $this->money($line->discountAmount);
        }

        $withholds = $line->itbisWithheld > 0 || $line->isrWithheld > 0;

        // E41 (purchases) and E47 (payments abroad) are withholding documents by definition.
        if ($withholds || in_array($type, [EcfTypeEnum::COMPRAS, EcfTypeEnum::PAGOS_EXTERIOR], true)) {
            $item['retperInd'] = 1;
            $item['retItbis'] = $this->money($line->itbisWithheld);
            $item['retIsr'] = $this->money($line->isrWithheld);
        }

        return $item;
    }

    /**
     * E47 payment details have no fedo field; v9 sends them as addInfo lines addressed to the DGII field by
     * group + name. Group/names follow the DGII e-CF XSD (IdDoc payment account, OtraMoneda); confirm on the
     * test host with the first E47.
     *
     * @return list<array{id: int, value: string, grp: string, name: string}>
     */
    private function foreignPaymentInfo(EcfDocument $document): array
    {
        $payment = $document->foreignPayment;

        if ($payment === null) {
            return [];
        }

        $fields = array_filter([
            ['IdDoc', 'TipoCuentaPago', $payment->accountType],
            ['IdDoc', 'NumeroCuentaPago', $payment->accountNumber],
            ['IdDoc', 'BancoPago', $payment->bank],
            ['OtraMoneda', 'TipoMoneda', $payment->foreignCurrency],
            ['OtraMoneda', 'MontoTotalOtraMoneda', $payment->foreignAmount !== null ? number_format($payment->foreignAmount, 2, '.', '') : null],
        ], fn (array $field): bool => $field[2] !== null && $field[2] !== '');

        $addInfo = [];
        foreach (array_values($fields) as $index => [$group, $name, $value]) {
            $addInfo[] = ['id' => $index + 1, 'value' => (string) $value, 'grp' => $group, 'name' => $name];
        }

        return $addInfo;
    }

    /**
     * WebPOS item tax codes: 0 exempt, 1 ITBIS 18%, 2 ITBIS 16%, 3 ITBIS 0%. E44 (special regime) is always
     * exempt and E46 (exports) always 0%, whatever the line says.
     */
    private function taxCode(EcfTypeEnum $type, EcfItbisRateEnum $itbis): int
    {
        return match (true) {
            $type === EcfTypeEnum::REGIMEN_ESPECIAL => 0,
            $type === EcfTypeEnum::EXPORTACION => 3,
            default => match ($itbis) {
                EcfItbisRateEnum::EXEMPT => 0,
                EcfItbisRateEnum::RATE_18 => 1,
                EcfItbisRateEnum::RATE_16 => 2,
                EcfItbisRateEnum::RATE_0 => 3,
            },
        };
    }

    private function assertDocumentLimits(EcfDocument $document): void
    {
        $count = count($document->lines);

        if ($count > DrEcfValidator::MAX_LINES) {
            throw new ValidationException("e-CF {$document->encf}: WebPOS takes at most " . DrEcfValidator::MAX_LINES . " items, got {$count}.");
        }

        if ($document->systemRef === '' || strlen($document->systemRef) > self::MAX_SYSTEM_REF) {
            throw new ValidationException("e-CF {$document->encf}: systemRef must be 1 to " . self::MAX_SYSTEM_REF . ' characters.');
        }

        if (mb_strlen($document->buyer->name) > self::MAX_CUSTOMER_NAME) {
            throw new ValidationException("e-CF {$document->encf}: customer name is longer than " . self::MAX_CUSTOMER_NAME . ' characters.');
        }

        foreach ($document->lines as $line) {
            if ($line->code === '' || mb_strlen($line->code) > self::MAX_ITEM_CODE) {
                throw new ValidationException("e-CF {$document->encf} line {$line->lineNumber}: item code must be 1 to " . self::MAX_ITEM_CODE . ' characters.');
            }

            if ($line->description === '' || mb_strlen($line->description) > self::MAX_ITEM_DESCRIPTION) {
                throw new ValidationException("e-CF {$document->encf} line {$line->lineNumber}: description must be 1 to " . self::MAX_ITEM_DESCRIPTION . ' characters.');
            }
        }
    }

    private function money(float $amount): float
    {
        return Money::cents($amount);
    }
}
