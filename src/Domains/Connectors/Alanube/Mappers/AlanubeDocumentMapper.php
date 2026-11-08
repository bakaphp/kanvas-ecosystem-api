<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Mappers;

use Baka\Support\Money;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;

/**
 * EcfDocument → the body of Alanube's per-type endpoint, which is the DGII e-CF model in English camelCase.
 *
 * Amounts are sent as Scribe booked them (never recomputed). DGII wants the headline amounts in DOP, so a
 * foreign-currency document is converted at its own exchange rate and the original amounts go in
 * `otherCurrency` / `otherCurrencyDetail`.
 *
 * Conditional DGII fields (per-rate totals, retentions, discounts) are only sent when non-zero: DGII rejects a
 * conditional field that is present when its condition does not hold.
 */
class AlanubeDocumentMapper
{
    public const string BASE_CURRENCY = 'DOP';

    // DGII's NombreItem and RazonModificacion limits.
    private const int ITEM_NAME_MAX = 80;
    private const int REASON_MAX = 90;

    // IndicadorNotaCredito: 1 when the note is issued more than 30 days after the e-CF it modifies.
    private const int CREDIT_NOTE_DAYS = 30;

    /**
     * @param array<string, mixed> $sender the company's DGII sender block (ConfigurationEnum::SENDER_PROFILE)
     */
    public function __construct(
        private readonly array $sender,
    ) {
    }

    public static function endpointFor(EcfTypeEnum $type): string
    {
        return match ($type) {
            EcfTypeEnum::CREDITO_FISCAL => 'fiscal-invoices',
            EcfTypeEnum::CONSUMO => 'invoices',
            EcfTypeEnum::NOTA_DEBITO => 'debit-notes',
            EcfTypeEnum::NOTA_CREDITO => 'credit-notes',
            EcfTypeEnum::COMPRAS => 'purchases',
            EcfTypeEnum::GASTOS_MENORES => 'minor-expenses',
            EcfTypeEnum::REGIMEN_ESPECIAL => 'special-regimes',
            EcfTypeEnum::GUBERNAMENTAL => 'gubernamentals',
            EcfTypeEnum::EXPORTACION => 'export-supports',
            EcfTypeEnum::PAGOS_EXTERIOR => 'payment-abroad-supports',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function map(EcfDocument $document): array
    {
        $rate = $this->toBaseRate($document);

        $body = [
            'idDoc' => $this->idDoc($document),
            'sender' => $this->sender($document),
        ];

        $buyer = $this->buyer($document);
        if ($buyer !== []) {
            $body['buyer'] = $buyer;
        }

        $body['totals'] = $this->totals($document, $rate);

        if ($rate !== 1.0) {
            $body['otherCurrency'] = $this->otherCurrency($document);
        }

        $body['itemDetails'] = array_map(
            fn (EcfLine $line): array => $this->item($line, $rate),
            $document->lines,
        );

        if ($document->reference !== null) {
            $body['informationReference'] = array_filter([
                'ncfModified' => $document->reference->encf,
                'ncfModifiedDate' => $document->reference->issueDate,
                'modificationCode' => $document->reference->modificationCode,
                'reasonForModification' => $document->reference->reason !== null
                    ? mb_substr($document->reference->reason, 0, self::REASON_MAX)
                    : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function idDoc(EcfDocument $document): array
    {
        $idDoc = ['encf' => $document->encf];

        // A credit note carries no sequence expiry at DGII (FechaVencimientoSecuencia is absent from E34).
        if ($document->type === EcfTypeEnum::NOTA_CREDITO) {
            $idDoc['creditNoteIndicator'] = $this->creditNoteIndicator($document);
        } else {
            $idDoc['sequenceDueDate'] = $document->sequenceExpiresAt;
        }

        $idDoc['incomeType'] = 1;
        $idDoc['paymentType'] = $document->isCredit ? 2 : 1;

        return $idDoc;
    }

    private function creditNoteIndicator(EcfDocument $document): int
    {
        if ($document->reference === null) {
            return 0;
        }

        $days = Carbon::parse($document->reference->issueDate)->diffInDays(Carbon::parse($document->issueDate));

        return $days > self::CREDIT_NOTE_DAYS ? 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function sender(EcfDocument $document): array
    {
        return array_filter([
            'rnc' => $this->sender['rnc'] ?? null,
            'companyName' => $this->sender['companyName'] ?? null,
            'tradename' => $this->sender['tradeName'] ?? null,
            'address' => $this->sender['address'] ?? null,
            'municipality' => $this->sender['municipality'] ?? null,
            'province' => $this->sender['province'] ?? null,
            'mail' => $this->sender['mail'] ?? null,
            'stampDate' => $document->issueDate,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(EcfDocument $document): array
    {
        $party = $document->buyer;
        $taxId = $party->taxId !== null ? preg_replace('/\D/', '', $party->taxId) : null;

        // An anonymous E32 (consumer, under DOP 250k) goes with no buyer block at all.
        if (($taxId === null || $taxId === '') && ! $document->type->requiresBuyerTaxId() && ! $party->isForeign) {
            return [];
        }

        return array_filter([
            'rnc' => $party->isForeign ? null : $taxId,
            'foreignIdentifier' => $party->isForeign ? $party->taxId : null,
            'companyName' => $party->name,
            'mail' => $party->email,
            'address' => $party->address,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(EcfDocument $document, float $rate): array
    {
        $byRate = $this->sumByRate($document->lines);
        $zeroRated = $byRate['r0']['present'];
        $withheldItbis = Money::sum(...array_map(fn (EcfLine $line): float => $line->itbisWithheld, $document->lines))->toFloat();
        $withheldIsr = Money::sum(...array_map(fn (EcfLine $line): float => $line->isrWithheld, $document->lines))->toFloat();

        return array_filter([
            'totalTaxedAmount' => $this->nonZero($this->converted($document->totals->taxed, $rate)),
            'i1AmountTaxed' => $this->nonZero($this->converted($byRate['r18']['taxed'], $rate)),
            'i2AmountTaxed' => $this->nonZero($this->converted($byRate['r16']['taxed'], $rate)),
            // A 0% item is taxed at zero, not exempt: its amount and rate are sent even though the tax is 0.
            'i3AmountTaxed' => $zeroRated ? $this->money($this->converted($byRate['r0']['taxed'], $rate)) : null,
            'exemptAmount' => $this->nonZero($this->converted($document->totals->exempt, $rate)),
            'itbisS1' => $byRate['r18']['present'] ? 18 : null,
            'itbisS2' => $byRate['r16']['present'] ? 16 : null,
            'itbisS3' => $zeroRated ? 0 : null,
            'itbisTotal' => $this->nonZero($this->converted($document->totals->itbis, $rate)),
            'itbis1Total' => $this->nonZero($this->converted($byRate['r18']['tax'], $rate)),
            'itbis2Total' => $this->nonZero($this->converted($byRate['r16']['tax'], $rate)),
            'itbis3Total' => $zeroRated ? 0.0 : null,
            'totalAmount' => Money::cents(Money::sum(
                $this->converted($document->totals->taxed, $rate),
                $this->converted($document->totals->exempt, $rate),
                $this->converted($document->totals->itbis, $rate),
            )),
            'itbisTotalRetained' => $this->nonZero($this->converted($withheldItbis, $rate)),
            'isrTotalRetention' => $this->nonZero($this->converted($withheldIsr, $rate)),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function otherCurrency(EcfDocument $document): array
    {
        $byRate = $this->sumByRate($document->lines);

        return array_filter([
            'currencyType' => strtoupper($document->currency),
            'exchangeRate' => $document->exchangeRate,
            'totalTaxedAmountOtherCurrency' => $this->nonZero($document->totals->taxed),
            'amountTaxed1OtherCurrency' => $this->nonZero($byRate['r18']['taxed']),
            'amountTaxed2OtherCurrency' => $this->nonZero($byRate['r16']['taxed']),
            'amountTaxed3OtherCurrency' => $byRate['r0']['present'] ? $this->money($byRate['r0']['taxed']) : null,
            'exemptAmountOtherCurrency' => $this->nonZero($document->totals->exempt),
            'itbisTotalOtherCurrency' => $this->nonZero($document->totals->itbis),
            'itbis1TotalOtherCurrency' => $this->nonZero($byRate['r18']['tax']),
            'itbis2TotalOtherCurrency' => $this->nonZero($byRate['r16']['tax']),
            'totalAmountOtherCurrency' => $this->money($document->totals->total),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(EcfLine $line, float $rate): array
    {
        $name = $line->description !== '' ? $line->description : $line->code;
        $item = ['lineNumber' => $line->lineNumber];

        if ($line->code !== '') {
            $item['itemCodeTable'] = [['codeType' => 'Interna', 'itemCode' => $line->code]];
        }

        $item['billingIndicator'] = $this->billingIndicator($line->itbis);

        if ($line->itbisWithheld > 0 || $line->isrWithheld > 0) {
            $item['retention'] = array_filter([
                'indicatorAgentWithholdingPerception' => 1,
                'itbisAmountWithheld' => $line->itbisWithheld > 0 ? $this->money($this->converted($line->itbisWithheld, $rate)) : null,
                'isrAmountWithheld' => $line->isrWithheld > 0 ? $this->money($this->converted($line->isrWithheld, $rate)) : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $item['itemName'] = mb_substr($name, 0, self::ITEM_NAME_MAX);

        if (mb_strlen($name) > self::ITEM_NAME_MAX) {
            $item['itemDescription'] = $name;
        }

        $item['goodServiceIndicator'] = $line->isService ? 2 : 1;
        $item['quantityItem'] = $line->quantity;
        $item['unitPriceItem'] = $this->money($this->converted($line->unitPrice, $rate));

        if ($line->discountAmount > 0) {
            $item['discountAmount'] = $this->money($this->converted($line->discountAmount, $rate));
        }

        if ($rate !== 1.0) {
            $item['otherCurrencyDetail'] = array_filter([
                'priceOtherCurrency' => $this->money($line->unitPrice),
                'discountOtherCurrency' => $this->nonZero($line->discountAmount),
                'amountItemOtherCurrency' => $this->money($line->subtotal),
            ], fn (mixed $value): bool => $value !== null);
        }

        $item['itemAmount'] = $this->money($this->converted($line->subtotal, $rate));

        return $item;
    }

    private function billingIndicator(EcfItbisRateEnum $rate): int
    {
        return match ($rate) {
            EcfItbisRateEnum::RATE_18 => 1,
            EcfItbisRateEnum::RATE_16 => 2,
            EcfItbisRateEnum::RATE_0 => 3,
            EcfItbisRateEnum::EXEMPT => 4,
        };
    }

    /**
     * @param EcfLine[] $lines
     * @return array{r18: array{taxed: float, tax: float, present: bool}, r16: array{taxed: float, tax: float, present: bool}, r0: array{taxed: float, tax: float, present: bool}}
     */
    private function sumByRate(array $lines): array
    {
        $empty = ['taxed' => 0.0, 'tax' => 0.0, 'present' => false];
        $sums = ['r18' => $empty, 'r16' => $empty, 'r0' => $empty];

        foreach ($lines as $line) {
            if ($line->itbis === EcfItbisRateEnum::EXEMPT) {
                continue;
            }

            $key = 'r' . $line->itbis->value;
            $sums[$key]['taxed'] = Money::sum($sums[$key]['taxed'], $line->subtotal)->toFloat();
            $sums[$key]['present'] = true;
        }

        // Same rule as EcfDocumentBuilder::totalsOf(): ITBIS per rate is the taxed base × the rate.
        foreach ([EcfItbisRateEnum::RATE_18, EcfItbisRateEnum::RATE_16] as $itbisRate) {
            $key = 'r' . $itbisRate->value;
            $sums[$key]['tax'] = Money::cents(Money::of($sums[$key]['taxed'])->multipliedBy((string) $itbisRate->percent())->dividedBy(100, 4));
        }

        return $sums;
    }

    private function toBaseRate(EcfDocument $document): float
    {
        return strtoupper($document->currency) === self::BASE_CURRENCY ? 1.0 : $document->exchangeRate;
    }

    private function nonZero(float $amount): ?float
    {
        $rounded = $this->money($amount);

        return $rounded === 0.0 ? null : $rounded;
    }

    private function money(float $amount): float
    {
        return Money::cents($amount);
    }

    private function converted(float $amount, float $rate): float
    {
        return Money::cents(Money::of($amount)->multipliedBy(Money::of($rate)));
    }
}
