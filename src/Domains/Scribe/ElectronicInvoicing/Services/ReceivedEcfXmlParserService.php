<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\ReceivedEcf;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\ReceivedEcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfQrCode;
use SimpleXMLElement;
use Throwable;

/**
 * Signed DGII e-CF XML (the vendor's document, whichever provider handed it to us) → ReceivedEcf.
 *
 * The XML is DGII's format, so there is one parser; the provider only stamps where the document came from.
 * Elements are matched by local name, so the XML-DSig namespace on the signature and any default namespace
 * a vendor's software adds don't matter. A document in another currency is booked in that currency: the
 * `OtraMoneda` amounts are the ones the vendor will be paid in, the DOP ones are its conversion.
 */
class ReceivedEcfXmlParserService
{
    // DGII IndicadorFacturacion: 0 not billable (informational, outside the totals), 1/2/3 ITBIS rates, 4 exempt.
    private const int NOT_BILLABLE = 0;

    public function parse(string $xml, EcfProviderEnum $provider, ?string $qrUrl = null): ReceivedEcf
    {
        $root = $this->load($xml);

        $type = EcfTypeEnum::tryFrom($this->text($root, 'Encabezado/IdDoc/TipoeCF') ?? '');
        $encf = $this->text($root, 'Encabezado/IdDoc/eNCF');
        $vendorTaxId = $this->text($root, 'Encabezado/Emisor/RNCEmisor');

        if ($type === null || $encf === null || $vendorTaxId === null) {
            throw new ValidationException('Received e-CF XML is missing TipoeCF, eNCF or RNCEmisor.');
        }

        $foreignCurrency = $this->text($root, 'Encabezado/OtraMoneda/TipoMoneda');
        $exchangeRate = $this->number($root, 'Encabezado/OtraMoneda/TipoCambio');
        $inForeign = $foreignCurrency !== null && $exchangeRate > 0;

        $totals = $inForeign
            ? new EcfTotals(
                exempt: $this->number($root, 'Encabezado/OtraMoneda/MontoExentoOtraMoneda'),
                taxed: $this->number($root, 'Encabezado/OtraMoneda/MontoGravadoTotalOtraMoneda'),
                itbis: $this->number($root, 'Encabezado/OtraMoneda/TotalITBISOtraMoneda'),
                total: $this->number($root, 'Encabezado/OtraMoneda/MontoTotalOtraMoneda'),
            )
            : new EcfTotals(
                exempt: $this->number($root, 'Encabezado/Totales/MontoExento'),
                taxed: $this->number($root, 'Encabezado/Totales/MontoGravadoTotal'),
                itbis: $this->number($root, 'Encabezado/Totales/TotalITBIS'),
                total: $this->number($root, 'Encabezado/Totales/MontoTotal'),
            );

        $lines = $this->lines(
            $root,
            $inForeign,
            $exchangeRate,
            $totals->itbis
        );

        if ($lines === []) {
            throw new ValidationException("Received e-CF {$encf} has no billable items.");
        }

        return new ReceivedEcf(
            vendorTaxId: $vendorTaxId,
            vendorName: $this->text($root, 'Encabezado/Emisor/RazonSocialEmisor') ?? $vendorTaxId,
            encf: $encf,
            type: $type,
            issueDate: $this->date($this->text($root, 'Encabezado/Emisor/FechaEmision')) ?? Carbon::today()->toDateString(),
            currency: $inForeign ? strtoupper($foreignCurrency) : 'DOP',
            exchangeRate: $inForeign ? $exchangeRate : 1.0,
            totals: $totals,
            lines: $lines,
            provider: $provider->value,
            dueDate: $this->date($this->text($root, 'Encabezado/IdDoc/FechaLimitePago')),
            securityCode: EcfQrCode::params($qrUrl)['CodigoSeguridad'] ?? null,
            signedAt: $this->text($root, 'FechaHoraFirma'),
            qrUrl: $qrUrl,
            reference: $this->reference($root),
            rawXml: $xml,
        );
    }

    /**
     * @return ReceivedEcfLine[]
     */
    private function lines(
        SimpleXMLElement $root,
        bool $inForeign,
        float $exchangeRate,
        float $documentItbis
    ): array {
        $rates = [
            1 => EcfItbisRateEnum::RATE_18,
            2 => EcfItbisRateEnum::RATE_16,
            3 => EcfItbisRateEnum::RATE_0,
            4 => EcfItbisRateEnum::EXEMPT,
        ];

        $parsed = [];

        foreach ($this->nodes($root, 'DetallesItems/Item') as $item) {
            $indicator = (int) ($this->text($item, 'IndicadorFacturacion') ?? self::NOT_BILLABLE);

            if (! isset($rates[$indicator])) {
                continue;
            }

            $quantity = $this->number($item, 'CantidadItem') ?: 1.0;
            $unitPrice = $inForeign
                ? ($this->number($item, 'OtraMonedaDetalle/PrecioOtraMoneda') ?: $this->number($item, 'PrecioUnitarioItem') / $exchangeRate)
                : $this->number($item, 'PrecioUnitarioItem');
            $amount = $inForeign
                ? ($this->number($item, 'OtraMonedaDetalle/MontoItemOtraMoneda') ?: $this->number($item, 'MontoItem') / $exchangeRate)
                : $this->number($item, 'MontoItem');

            // MontoItem is already net of discounts and surcharges; a surcharge shows as a higher unit price.
            $gross = round($quantity * $unitPrice, 2);
            if ($amount > $gross) {
                $unitPrice = $amount / $quantity;
                $gross = $amount;
            }

            $itbis = $rates[$indicator];

            $parsed[] = [
                'lineNumber' => (int) ($this->text($item, 'NumeroLinea') ?? count($parsed) + 1),
                'description' => $this->text($item, 'NombreItem') ?? 'Item',
                'code' => $this->text($item, 'TablaCodigosItem/CodigosItem/CodigoItem'),
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'discount' => round($gross - $amount, 2),
                'amount' => $amount,
                'itbis' => $itbis,
                'tax' => round($amount * $itbis->percent() / 100, 2),
                'isService' => $this->text($item, 'IndicadorBienoServicio') === '2',
            ];
        }

        $parsed = $this->reconcileTax($parsed, $documentItbis);

        return array_map(fn (array $line): ReceivedEcfLine => new ReceivedEcfLine(
            lineNumber: $line['lineNumber'],
            description: $line['description'],
            quantity: $line['quantity'],
            unitPrice: $line['unitPrice'],
            discountAmount: $line['discount'],
            amount: $line['amount'],
            itbis: $line['itbis'],
            tax: $line['tax'],
            isService: $line['isService'],
            code: $line['code'],
        ), $parsed);
    }

    /**
     * Per-line rounding can leave the sum a cent off TotalITBIS, and the vendor's total is what we owe, so
     * the remainder goes on the largest taxed line.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function reconcileTax(array $lines, float $documentItbis): array
    {
        $difference = round($documentItbis - array_sum(array_column($lines, 'tax')), 2);
        $largest = null;

        foreach ($lines as $index => $line) {
            if ($line['tax'] > 0 && ($largest === null || $line['tax'] > $lines[$largest]['tax'])) {
                $largest = $index;
            }
        }

        if ($difference !== 0.0 && $largest !== null) {
            $lines[$largest]['tax'] = round($lines[$largest]['tax'] + $difference, 2);
        }

        return $lines;
    }

    private function reference(SimpleXMLElement $root): ?EcfReference
    {
        $encf = $this->text($root, 'InformacionReferencia/NCFModificado');

        if ($encf === null) {
            return null;
        }

        return new EcfReference(
            encf: $encf,
            issueDate: $this->date($this->text($root, 'InformacionReferencia/FechaNCFModificado')) ?? '',
            modificationCode: (int) ($this->text($root, 'InformacionReferencia/CodigoModificacion') ?? 0),
            reason: $this->text($root, 'InformacionReferencia/RazonModificacion'),
        );
    }

    private function load(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: a vendor-supplied document must never make us fetch anything.
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } catch (Throwable) {
            $root = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $root instanceof SimpleXMLElement) {
            throw new ValidationException('Received e-CF is not valid XML.');
        }

        return $root;
    }

    /**
     * @return SimpleXMLElement[]
     */
    private function nodes(SimpleXMLElement $context, string $path): array
    {
        $steps = array_map(fn (string $step): string => "*[local-name()='{$step}']", explode('/', $path));
        $found = $context->xpath('./' . implode('/', $steps));

        return is_array($found) ? $found : [];
    }

    private function text(SimpleXMLElement $context, string $path): ?string
    {
        $node = $this->nodes($context, $path)[0] ?? null;
        $value = $node !== null ? trim((string) $node) : '';

        return $value === '' ? null : $value;
    }

    private function number(SimpleXMLElement $context, string $path): float
    {
        return (float) ($this->text($context, $path) ?? 0);
    }

    /**
     * DGII dates are dd-mm-yyyy.
     */
    private function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-m-Y', $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
