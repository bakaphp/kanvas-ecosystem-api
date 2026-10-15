<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StrJsonToArrayTest extends TestCase
{
    public static function decodedValues(): array
    {
        return [
            'object' => ['{"enabled":true,"count":2}', ['enabled' => true, 'count' => 2]],
            'list' => ['["read","write"]', ['read', 'write']],
            'integer' => ['42', 42],
            'one' => ['1', 1],
            'zero' => ['0', 0],
            'float' => ['3.14', 3.14],
            'boolean' => ['true', true],
            'null' => ['null', null],
        ];
    }

    #[DataProvider('decodedValues')]
    public function testValidJsonStillDecodes(string $json, mixed $expected): void
    {
        $this->assertSame($expected, Str::jsonToArray($json));
    }

    /**
     * A bare number is valid JSON, so json_decode happily turns a Slack client id or a 64-bit id into
     * a float and the trailing digits are gone for good.
     */
    public static function precisionLosingNumbers(): array
    {
        return [
            'slack client id' => ['474083765751.10750363865122'],
            'beyond 64-bit' => ['18446744073709551616'],
            'scientific notation' => ['1e20'],
            'trailing zero decimal' => ['42.50'],
        ];
    }

    #[DataProvider('precisionLosingNumbers')]
    public function testNumbersThatWouldLosePrecisionStayStrings(string $value): void
    {
        $this->assertSame($value, Str::jsonToArray($value));
    }

    public function testNonJsonAndNonStringsPassThrough(): void
    {
        $this->assertSame('001234', Str::jsonToArray('001234'));
        $this->assertSame('plain text', Str::jsonToArray('plain text'));
        $this->assertSame(['a' => 1], Str::jsonToArray(['a' => 1]));
        $this->assertSame(7, Str::jsonToArray(7));
        $this->assertNull(Str::jsonToArray(null));
    }
}
