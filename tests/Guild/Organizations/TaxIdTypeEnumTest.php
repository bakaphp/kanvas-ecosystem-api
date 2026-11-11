<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use PHPUnit\Framework\TestCase;

final class TaxIdTypeEnumTest extends TestCase
{
    public function test_accepts_checks_the_known_formats(): void
    {
        $this->assertTrue(TaxIdTypeEnum::DO_RNC->accepts('131794321'));
        $this->assertFalse(TaxIdTypeEnum::DO_RNC->accepts('00112345678'));
        $this->assertTrue(TaxIdTypeEnum::DO_CEDULA->accepts('00112345678'));
        $this->assertFalse(TaxIdTypeEnum::DO_CEDULA->accepts('131794321'));
        $this->assertTrue(TaxIdTypeEnum::US_EIN->accepts('123456789'));
        $this->assertFalse(TaxIdTypeEnum::US_EIN->accepts('12345678A'));
        $this->assertTrue(TaxIdTypeEnum::MX_RFC->accepts('XAXX010101000'));
        $this->assertTrue(TaxIdTypeEnum::MX_RFC->accepts('abc-010101-ab1'));
        $this->assertFalse(TaxIdTypeEnum::MX_RFC->accepts('123456789'));
        $this->assertTrue(TaxIdTypeEnum::SV_NIT->accepts('06141234567890'));
        $this->assertTrue(TaxIdTypeEnum::SV_NRC->accepts('1234567'));
        $this->assertTrue(TaxIdTypeEnum::PA_RUC->accepts('8-123-456'));
        $this->assertTrue(TaxIdTypeEnum::OTHER->accepts('anything'));
    }

    public function test_country_and_label(): void
    {
        $this->assertSame('DO', TaxIdTypeEnum::DO_CEDULA->country());
        $this->assertSame('Cédula', TaxIdTypeEnum::DO_CEDULA->label());
        $this->assertSame('MX', TaxIdTypeEnum::MX_RFC->country());
        $this->assertSame('EIN', TaxIdTypeEnum::US_EIN->label());
        $this->assertNull(TaxIdTypeEnum::OTHER->country());
    }

    public function test_infer_by_country_and_shape(): void
    {
        $this->assertSame(TaxIdTypeEnum::DO_RNC, TaxIdTypeEnum::infer('DO', '131794321'));
        $this->assertSame(TaxIdTypeEnum::DO_CEDULA, TaxIdTypeEnum::infer('do', '00112345678'));
        $this->assertSame(TaxIdTypeEnum::MX_RFC, TaxIdTypeEnum::infer('MX', 'XAXX010101000'));
        $this->assertSame(TaxIdTypeEnum::US_EIN, TaxIdTypeEnum::infer('US', '123456789'));
        $this->assertSame(TaxIdTypeEnum::SV_NIT, TaxIdTypeEnum::infer('SV', '06141234567890'));
    }

    public function test_infer_is_null_when_it_cannot_tell(): void
    {
        $this->assertNull(TaxIdTypeEnum::infer('DO', '1234567890'));
        $this->assertNull(TaxIdTypeEnum::infer('US', 'B12345678'));
        $this->assertNull(TaxIdTypeEnum::infer(null, '131794321'));
        $this->assertNull(TaxIdTypeEnum::infer('FR', '131794321'));
    }
}
