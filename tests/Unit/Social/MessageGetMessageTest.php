<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

class MessageGetMessageTest extends TestCaseUnit
{
    private function messageWithRaw(mixed $raw): Message
    {
        $message = new Message();
        $message->setRawAttributes(['message' => $raw], true);

        return $message;
    }

    public function testGetMessageReturnsArrayForObjectJson(): void
    {
        $message = $this->messageWithRaw('{"content":"hello"}');

        $this->assertSame(['content' => 'hello'], $message->getMessage());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scalarJsonProvider(): array
    {
        return [
            'bare int' => ['571'],
            'bare float' => ['1.5'],
            'bare bool' => ['true'],
            'bare null' => ['null'],
            'bare quoted string' => ['"just a string"'],
        ];
    }

    /**
     * Str::isJson() is true for bare scalars, so json_decode() yields a non-array
     * (int/float/bool/null/string) — getMessage() must still return [] not fatal on
     * the array return type. Regression for KANVAS-ECOSYSTEM-62A.
     *
     */
    #[DataProvider('scalarJsonProvider')]
    public function testGetMessageReturnsEmptyArrayForScalarJson(string $raw): void
    {
        $message = $this->messageWithRaw($raw);

        $this->assertSame([], $message->getMessage());
    }

    public function testGetMessageReturnsEmptyArrayForNonStringRaw(): void
    {
        $message = $this->messageWithRaw(571);

        $this->assertSame([], $message->getMessage());
    }

    public function testGetMessageReturnsEmptyArrayForPlainString(): void
    {
        $message = $this->messageWithRaw('just plain text');

        $this->assertSame([], $message->getMessage());
    }
}
