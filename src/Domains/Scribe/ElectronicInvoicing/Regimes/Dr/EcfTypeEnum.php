<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;

/**
 * DGII e-CF document types. The value is the two-digit code that prefixes the e-NCF (E31…).
 */
enum EcfTypeEnum: string implements FiscalDocumentTypeInterface
{
    case CREDITO_FISCAL = '31';
    case CONSUMO = '32';
    case NOTA_DEBITO = '33';
    case NOTA_CREDITO = '34';
    case COMPRAS = '41';
    case GASTOS_MENORES = '43';
    case REGIMEN_ESPECIAL = '44';
    case GUBERNAMENTAL = '45';
    case EXPORTACION = '46';
    case PAGOS_EXTERIOR = '47';

    /**
     * The bare code or the e-NCF series: 31 or E31.
     */
    public static function parse(?string $code): ?self
    {
        return self::tryFrom(ltrim(strtoupper(trim((string) $code)), 'E'));
    }

    public function code(): string
    {
        return $this->value;
    }

    public function series(): string
    {
        return 'E' . $this->value;
    }

    public function sequenceDigits(): int
    {
        return 10;
    }

    public function isNote(): bool
    {
        return $this === self::NOTA_DEBITO || $this === self::NOTA_CREDITO;
    }

    /**
     * E32 below DOP 250,000 and E43 are the only types DGII accepts without the buyer's RNC.
     */
    public function requiresBuyerTaxId(): bool
    {
        return ! in_array($this, [self::CONSUMO, self::GASTOS_MENORES], true);
    }

    /**
     * Documents the company issues for what it buys or pays, not for what it sells: they come from a bill
     * or an expense, and the "buyer" block carries the supplier or payee.
     */
    public function isPurchase(): bool
    {
        return in_array($this, [self::COMPRAS, self::GASTOS_MENORES, self::PAGOS_EXTERIOR], true);
    }

    /**
     * The DGII name printed on the document.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREDITO_FISCAL => 'Factura de Crédito Fiscal Electrónica',
            self::CONSUMO => 'Factura de Consumo Electrónica',
            self::NOTA_DEBITO => 'Nota de Débito Electrónica',
            self::NOTA_CREDITO => 'Nota de Crédito Electrónica',
            self::COMPRAS => 'Comprobante Electrónico de Compras',
            self::GASTOS_MENORES => 'Comprobante Electrónico para Gastos Menores',
            self::REGIMEN_ESPECIAL => 'Comprobante Electrónico para Regímenes Especiales',
            self::GUBERNAMENTAL => 'Comprobante Electrónico Gubernamental',
            self::EXPORTACION => 'Comprobante Electrónico para Exportaciones',
            self::PAGOS_EXTERIOR => 'Comprobante Electrónico para Pagos al Exterior',
        };
    }
}
