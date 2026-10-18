<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\DataTransferObject\TradeIn;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class TradeInFromMessageTest extends TestCase
{
    public function testReadsTheExtensionLabelValuePayload(): void
    {
        $tradeIn = TradeIn::from($this->makeMessage('add-trade', [
            ['label' => 'Vehicle Identification Number (VIN)', 'value' => '1abcd23efgh456789'],
            ['label' => 'Transmission', 'value' => 'automatic'],
            ['label' => 'Interior color', 'value' => 'black'],
            ['label' => 'Odometer', 'value' => '12,345'],
        ]), $this->makeLead());

        $this->assertSame('1ABCD23EFGH456789', $tradeIn->vin);
        $this->assertSame('automatic', $tradeIn->transmission);
        $this->assertSame('black', $tradeIn->interiorColor);
        $this->assertSame(12345, $tradeIn->mileage);
        $this->assertNull($tradeIn->year);
        $this->assertNull($tradeIn->make);
    }

    public function testReadsTheWebFormPayload(): void
    {
        $tradeIn = TradeIn::from($this->makeMessage('add-trade', [
            'form' => [
                'year' => '2021',
                'make' => 'Acme',
                'model' => 'Roadster',
                'ext_color' => 'blue',
                'body_style' => 'coupe',
                'engine' => 'V6',
                'mileage' => '12,000',
                'value' => '15000.456',
                'payoff_amount' => '9000',
            ],
        ]), $this->makeLead());

        $this->assertSame('2021', $tradeIn->year);
        $this->assertSame('Roadster', $tradeIn->model);
        $this->assertSame('blue', $tradeIn->exteriorColor);
        $this->assertSame('coupe', $tradeIn->bodyStyle);
        $this->assertSame('V6', $tradeIn->engineName);
        $this->assertSame(12000, $tradeIn->mileage);
        $this->assertSame(15000.46, $tradeIn->value);
        $this->assertSame(9000.0, $tradeIn->payOff);
        $this->assertFalse($tradeIn->hasCompleteVehicleInfo());
    }

    public function testPayoffFormCarriesOnlyThePayoff(): void
    {
        $tradeIn = TradeIn::from($this->makeMessage('payoff-form', [
            'form' => ['ten_day_payoff' => ['value' => '4321.5']],
        ]), $this->makeLead());

        $this->assertNull($tradeIn->vin);
        $this->assertSame(4321.5, $tradeIn->payOff);
        $this->assertTrue($tradeIn->isPayoffOnly());
    }

    private function makeMessage(string $verb, array $data): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'message' => [
                    'verb' => $verb,
                    'status' => 'submitted',
                    'data' => $data,
                ],
            ]);
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
