<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit;

use Kanvas\Workflow\Rules\Models\Rule;
use ReflectionMethod;
use Tests\TestCase;

final class RuleFormatValueTest extends TestCase
{
    private function invokeFormatValue(mixed $value): string
    {
        $method = new ReflectionMethod(Rule::class, 'formatValue');

        return $method->invoke(new Rule(), $value);
    }

    public function testStringFalseRendersAsBooleanLiteral(): void
    {
        $this->assertSame('false', $this->invokeFormatValue('false'));
        $this->assertSame('false', $this->invokeFormatValue('FALSE'));
        $this->assertSame('false', $this->invokeFormatValue(' false '));
    }

    public function testStringTrueRendersAsBooleanLiteral(): void
    {
        $this->assertSame('true', $this->invokeFormatValue('true'));
        $this->assertSame('true', $this->invokeFormatValue('TRUE'));
    }

    public function testStringNullRendersAsNullLiteral(): void
    {
        $this->assertSame('null', $this->invokeFormatValue('null'));
    }

    public function testNumericStringRendersUnquoted(): void
    {
        $this->assertSame('697', $this->invokeFormatValue('697'));
        $this->assertSame('0', $this->invokeFormatValue('0'));
        $this->assertSame('3.14', $this->invokeFormatValue('3.14'));
    }

    public function testPlainStringIsQuoted(): void
    {
        $this->assertSame("'hello'", $this->invokeFormatValue('hello'));
    }

    public function testStringWithSingleQuoteIsEscaped(): void
    {
        $this->assertSame("'O\\'Brien'", $this->invokeFormatValue("O'Brien"));
    }

    public function testBooleanValues(): void
    {
        $this->assertSame('true', $this->invokeFormatValue(true));
        $this->assertSame('false', $this->invokeFormatValue(false));
    }

    public function testNumericValues(): void
    {
        $this->assertSame('42', $this->invokeFormatValue(42));
        $this->assertSame('1.5', $this->invokeFormatValue(1.5));
    }

    public function testNullValue(): void
    {
        $this->assertSame('null', $this->invokeFormatValue(null));
    }
}
