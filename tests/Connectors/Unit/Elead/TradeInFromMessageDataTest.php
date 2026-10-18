<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use Kanvas\Connectors\Elead\DataTransferObject\TradeIn;
use Tests\TestCase;

final class TradeInFromMessageDataTest extends TestCase
{
    public function testParsesTheExtensionLabelValuePayload(): void
    {
        $tradeIn = TradeIn::fromMessageData([
            ['label' => 'Vehicle Identification Number (VIN)', 'value' => '1abcd23efgh456789'],
            ['label' => 'Transmission', 'value' => 'automatic'],
            ['label' => 'Exterior color', 'value' => 'black'],
            ['label' => 'Interior color', 'value' => 'black'],
            ['label' => 'Odometer', 'value' => '12,345'],
            ['label' => 'Upload odometer photo', 'value' => '1000001'],
        ]);

        $this->assertSame('1ABCD23EFGH456789', $tradeIn->vin);
        $this->assertSame('automatic', $tradeIn->transmission);
        $this->assertSame('black', $tradeIn->exteriorColor);
        $this->assertSame('black', $tradeIn->interiorColor);
        $this->assertSame(12345, $tradeIn->estimatedMileage);
        $this->assertSame(0, $tradeIn->year);
        $this->assertTrue($tradeIn->hasEnoughDetails());
    }

    public function testParsesTheWebFormPayload(): void
    {
        $tradeIn = TradeIn::fromMessageData([
            'form' => [
                'year' => '2021',
                'make' => 'Acme',
                'model' => 'Roadster',
                'trim' => str_repeat('x', 60),
                'mileage' => '12000',
                'int_color' => 'red',
                'trans' => 'manual',
            ],
        ]);

        $this->assertSame(2021, $tradeIn->year);
        $this->assertSame('Roadster', $tradeIn->model);
        $this->assertSame(50, strlen($tradeIn->trim));
        $this->assertSame('red', $tradeIn->interiorColor);
        $this->assertSame('manual', $tradeIn->transmission);
        $this->assertSame('', $tradeIn->vin);
        $this->assertTrue($tradeIn->hasEnoughDetails());
    }

    public function testWithoutVinOrYearMakeModelIsNotEnoughForElead(): void
    {
        $tradeIn = TradeIn::fromMessageData([
            ['label' => 'Odometer', 'value' => '100'],
            ['label' => 'Make', 'value' => 'Ford'],
        ]);

        $this->assertFalse($tradeIn->hasEnoughDetails());
    }
}
