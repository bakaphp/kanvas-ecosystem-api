<?php

declare(strict_types=1);

namespace Tests\Intelligence\Enums;

use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Tests\TestCase;

class IntelligenceModeEnumTest extends TestCase
{
    public function testIdleIsOff(): void
    {
        $this->assertTrue(IntelligenceModeEnum::IDLE->isOff());
    }

    public function testOffIsOff(): void
    {
        $this->assertTrue(IntelligenceModeEnum::OFF->isOff());
    }

    public function testFullOnIsNotOff(): void
    {
        $this->assertFalse(IntelligenceModeEnum::FULL_ON->isOff());
    }

    public function testSupportIsNotOff(): void
    {
        $this->assertFalse(IntelligenceModeEnum::SUPPORT->isOff());
    }

    public function testIdleAndOffAreDifferentCases(): void
    {
        $this->assertNotSame(IntelligenceModeEnum::IDLE, IntelligenceModeEnum::OFF);
        $this->assertEquals('IDLE', IntelligenceModeEnum::IDLE->value);
        $this->assertEquals('OFF', IntelligenceModeEnum::OFF->value);
    }

    public function testTryFromIdleReturnsIdleEnum(): void
    {
        $enum = IntelligenceModeEnum::tryFrom('IDLE');
        $this->assertNotNull($enum);
        $this->assertTrue($enum->isOff());
    }

    public function testTryFromOffReturnsOffEnum(): void
    {
        $enum = IntelligenceModeEnum::tryFrom('OFF');
        $this->assertNotNull($enum);
        $this->assertTrue($enum->isOff());
    }

    public function testTryFromUnknownStringReturnsNull(): void
    {
        $this->assertNull(IntelligenceModeEnum::tryFrom('UNKNOWN'));
    }
}
