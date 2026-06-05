<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCaseUnit;

final class StrPhoneTest extends TestCaseUnit
{
    public function testToE164PrependsPlusOneFor10DigitUsNumber(): void
    {
        $this->assertSame('+14047907130', Str::toE164('4047907130'));
    }

    public function testToE164PreservesUsNumberWithCountryCode(): void
    {
        $this->assertSame('+14047907130', Str::toE164('14047907130'));
    }

    public function testToE164KeepsExistingE164(): void
    {
        $this->assertSame('+14047907130', Str::toE164('+14047907130'));
    }

    public function testToE164StripsDashesAndParens(): void
    {
        $this->assertSame('+14047907130', Str::toE164('(404) 790-7130'));
        $this->assertSame('+14047907130', Str::toE164('404-790-7130'));
        $this->assertSame('+14047907130', Str::toE164('+1 (404) 790-7130'));
    }

    public function testToE164StripsSpaces(): void
    {
        $this->assertSame('+14047907130', Str::toE164(' 404 790 7130 '));
    }

    public function testToE164SupportsDominicanAreaCodes(): void
    {
        $this->assertSame('+18098646241', Str::toE164('8098646241'));
        $this->assertSame('+18298646241', Str::toE164('829-864-6241'));
        $this->assertSame('+18498646241', Str::toE164('+1 849 864 6241'));
    }

    public function testToE164InternationalNumberKeepsCountryCodeAndAddsPlus(): void
    {
        $this->assertSame('+442012345678', Str::toE164('442012345678'));
        $this->assertSame('+442012345678', Str::toE164('+44 20 1234 5678'));
    }

    public function testToE164ReturnsEmptyStringForEmptyOrNullInput(): void
    {
        $this->assertSame('', Str::toE164(null));
        $this->assertSame('', Str::toE164(''));
        $this->assertSame('', Str::toE164('---'));
    }

    public function testToE164RespectsCustomDefaultCountryCode(): void
    {
        $this->assertSame('+525512345678', Str::toE164('5512345678', '52'));
        // 11+ digit input ignores the default; the digits already imply a country code.
        $this->assertSame('+525512345678', Str::toE164('525512345678', '1'));
    }

    public function testToE164NeverDoublesPlusOnAlreadyPrefixedShortInput(): void
    {
        // `+` plus 10 digits — `digitsOnly` strips the +, then the 10-digit branch adds +1.
        $this->assertSame('+14047907130', Str::toE164('+4047907130'));
    }
}
