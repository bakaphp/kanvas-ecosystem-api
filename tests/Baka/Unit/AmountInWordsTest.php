<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\AmountInWords;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    /**
     * @return array<string, array{float, string}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => [0.0, 'cero con 00/100'],
            'one' => [1.0, 'uno con 00/100'],
            'teens' => [16.0, 'dieciséis con 00/100'],
            'twenties' => [29.0, 'veintinueve con 00/100'],
            'tens with units' => [45.0, 'cuarenta y cinco con 00/100'],
            'exact hundred' => [100.0, 'cien con 00/100'],
            'hundred and one' => [101.0, 'ciento uno con 00/100'],
            'five hundreds' => [500.0, 'quinientos con 00/100'],
            'thousand' => [1000.0, 'mil con 00/100'],
            'thousand and one' => [1001.0, 'mil uno con 00/100'],
            'cents' => [1250.5, 'mil doscientos cincuenta con 50/100'],
            'cents under ten' => [10.05, 'diez con 05/100'],
            'twenty-one thousand' => [21000.0, 'veintiún mil con 00/100'],
            'thirty-one thousand' => [31000.0, 'treinta y un mil con 00/100'],
            'hundred one thousand' => [101000.0, 'ciento un mil con 00/100'],
            'one million' => [1000000.0, 'un millón con 00/100'],
            'two million' => [2000000.0, 'dos millones con 00/100'],
            'million and thousands' => [1234567.89, 'un millón doscientos treinta y cuatro mil quinientos sesenta y siete con 89/100'],
            'twenty-one million' => [21000000.0, 'veintiún millones con 00/100'],
            'thousand million' => [1000000000.0, 'mil millones con 00/100'],
            'rounds the cents' => [0.999, 'uno con 00/100'],
        ];
    }

    #[DataProvider('amounts')]
    public function testWritesTheAmountInSpanish(float $amount, string $expected): void
    {
        $this->assertSame($expected, AmountInWords::spanish($amount));
    }

    public function testRefusesANegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AmountInWords::spanish(-1.0);
    }
}
