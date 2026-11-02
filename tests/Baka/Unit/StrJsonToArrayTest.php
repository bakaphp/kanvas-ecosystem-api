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
            'zoho lead id, exact in PHP but not in JavaScript' => ['2896936000576627379'],
            'first integer JavaScript cannot hold' => ['9007199254740992'],
            'negative beyond the JavaScript range' => ['-9007199254740992'],
        ];
    }

    #[DataProvider('precisionLosingNumbers')]
    public function testNumbersThatWouldLosePrecisionStayStrings(string $value): void
    {
        $this->assertSame($value, Str::jsonToArray($value));
    }

    /**
     * phpredis serializes scalars, so a custom field stored as an int comes back as an int, not JSON.
     */
    public function testAnIntegerBeyondTheJavaScriptRangeBecomesAString(): void
    {
        $this->assertSame('2896936000576711333', Str::jsonToArray(2896936000576711333));
        $this->assertSame('-2896936000576711333', Str::jsonToArray(-2896936000576711333));
        $this->assertSame((string) PHP_INT_MAX, Str::jsonToArray(PHP_INT_MAX));
        $this->assertSame(Str::JSON_SAFE_INTEGER_MAX, Str::jsonToArray(Str::JSON_SAFE_INTEGER_MAX));
    }

    public function testTheLargestJavaScriptSafeIntegerStillDecodes(): void
    {
        $this->assertSame(Str::JSON_SAFE_INTEGER_MAX, Str::jsonToArray('9007199254740991'));
        $this->assertSame(-Str::JSON_SAFE_INTEGER_MAX, Str::jsonToArray('-9007199254740991'));
    }

    public function testAZohoIdRoundTripsThroughJsonAsAString(): void
    {
        $encoded = json_encode(['value' => Str::jsonToArray('2896936000576627379')]);

        $this->assertSame('{"value":"2896936000576627379"}', $encoded);
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
