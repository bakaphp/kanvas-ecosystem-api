<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\QuickBooks;

use Kanvas\Connectors\QuickBooks\Services\QuickBooksQueryService;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class QuickBooksQueryServiceTest extends TestCase
{
    public function testEscapesSingleQuotes(): void
    {
        $this->assertSame(
            "o\\'brien@example.com",
            QuickBooksQueryService::escapeString("o'brien@example.com")
        );
    }

    /**
     * The previous `str_replace("'", "\'", ...)` left the backslash untouched, so a trailing
     * backslash escaped the escape and let the quote close the literal.
     */
    public function testEscapesBackslashBeforeQuoteSoTheLiteralCannotBeClosed(): void
    {
        $payload = "x\\' OR Id = '1";

        $this->assertSame(
            "x\\\\\\' OR Id = \\'1",
            QuickBooksQueryService::escapeString($payload)
        );
    }

    public function testStripsControlCharacters(): void
    {
        $this->assertSame(
            'abc',
            QuickBooksQueryService::escapeString("a\x00b\nc")
        );
    }

    public function testNullBecomesEmptyString(): void
    {
        $this->assertSame('', QuickBooksQueryService::escapeString(null));
    }

    public function testEscapeIdAcceptsIntuitIds(): void
    {
        $this->assertSame('123', QuickBooksQueryService::escapeId('123'));
        $this->assertSame('a1b2-c3', QuickBooksQueryService::escapeId('a1b2-c3'));
    }

    public function testEscapeIdRejectsInjectionPayload(): void
    {
        $this->expectException(ValidationException::class);

        QuickBooksQueryService::escapeId("1' OR '1'='1");
    }

    public function testEscapeIdRejectsEmptyId(): void
    {
        $this->expectException(ValidationException::class);

        QuickBooksQueryService::escapeId(null);
    }
}
