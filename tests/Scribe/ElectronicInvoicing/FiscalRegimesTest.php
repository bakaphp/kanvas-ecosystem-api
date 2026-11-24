<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Tests\TestCase;

class FiscalRegimesTest extends TestCase
{
    public function test_a_country_resolves_to_its_regime_and_types(): void
    {
        $regime = FiscalRegimes::for('do');

        $this->assertInstanceOf(DrFiscalRegime::class, $regime);
        $this->assertSame('DO', $regime->country());
        $this->assertSame(EcfTypeEnum::cases(), $regime->documentTypes());
        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, $regime->documentType('31'));
        $this->assertSame(EcfTypeEnum::NOTA_CREDITO, $regime->documentType('e34'));
        $this->assertNull($regime->documentType('99'));
        $this->assertSame('DOP', $regime->currency());
        $source = $regime->officialRateSource();
        $this->assertSame(['bcrd', 'buy', 'DOP'], [$source->bank, $source->type, $source->localCurrency]);
    }

    public function test_an_unsupported_country_has_no_regime(): void
    {
        $this->assertNull(FiscalRegimes::find('SV'));

        $this->expectException(ValidationException::class);
        FiscalRegimes::for('SV');
    }

    public function test_a_stored_code_reads_through_its_rows_country(): void
    {
        $range = new FiscalNumberRange(['country' => 'DO', 'document_type' => '31']);
        $foreign = new FiscalNumberRange(['country' => 'SV', 'document_type' => '31']);

        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, $range->fiscalDocumentType());
        $this->assertSame('Factura de Crédito Fiscal Electrónica', $range->documentTypeLabel());
        $this->assertNull($foreign->fiscalDocumentType(), 'the same code in another country is not the DR type');
    }
}
