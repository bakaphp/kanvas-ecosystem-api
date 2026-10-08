<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Arr;
use Tests\TestCase;

final class ArrFromLabelValuePairsTest extends TestCase
{
    public function testFlattensAnExtensionTradeInPayload(): void
    {
        $data = Arr::fromLabelValuePairs([
            ['label' => 'VIN', 'value' => 'JM1BPBJYXN1519045'],
            ['label' => 'Exterior color', 'value' => 'white'],
            ['label' => 'Interior color', 'value' => 'beige'],
            ['label' => 'Odometer', 'value' => '333'],
            ['label' => 'Last 4 digits of SSN', 'value' => '1234'],
            ['label' => ' AÑO ', 'value' => '2022'],
        ]);

        $this->assertSame([
            'vin' => 'JM1BPBJYXN1519045',
            'exterior_color' => 'white',
            'interior_color' => 'beige',
            'odometer' => '333',
            'last_4_digits_of_ssn' => '1234',
            'año' => '2022',
        ], $data);
    }

    public function testSkipsEntriesWithoutALabelAndKeyedPayloads(): void
    {
        $this->assertSame(
            ['vin' => null],
            Arr::fromLabelValuePairs([
                'form' => ['vin' => 'X'],
                ['value' => 'orphan'],
                ['label' => 'VIN'],
            ])
        );
    }
}
