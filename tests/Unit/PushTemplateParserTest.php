<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Notifications\Support\PushTemplateParser;
use PHPUnit\Framework\TestCase;

class PushTemplateParserTest extends TestCase
{
    public function testStrictParsesWellFormedJson(): void
    {
        $rendered = '{"title":"New Like","message":"You got a like","subtitle":"hi"}';

        $parsed = PushTemplateParser::parseStrict($rendered);

        $this->assertSame([
            'title' => 'New Like',
            'message' => 'You got a like',
            'subtitle' => 'hi',
        ], $parsed);
    }

    public function testStrictReturnsNullForInvalidJson(): void
    {
        $rendered = '{"title":"New Like","message":"hello "world""}';

        $this->assertNull(PushTemplateParser::parseStrict($rendered));
    }

    public function testStrictRepairsStrayBackslashFromEscapedApostrophe(): void
    {
        // Exact Sentry KANVAS-ECOSYSTEM-5RJ payload: the image name "Man's" was
        // addslashes-escaped then Blade HTML-escaped, leaving a stray `\&` that
        // makes the string invalid JSON. The repair drops the stray backslash so
        // strict parsing succeeds (no lenient fallback, no Sentry report).
        $rendered = '{"title":"New AI creation!","message": "Your image for Man\\&#039;s Beautiful Hair has been processed"}';

        $parsed = PushTemplateParser::parseStrict($rendered);

        $this->assertSame('New AI creation!', $parsed['title']);
        $this->assertSame('Your image for Man&#039;s Beautiful Hair has been processed', $parsed['message']);
        $this->assertNull($parsed['subtitle']);
    }

    public function testStrictPreservesValidJsonEscapes(): void
    {
        $rendered = '{"title":"Line\\nbreak","message":"Quote \\" and slash \\/ kept"}';

        $parsed = PushTemplateParser::parseStrict($rendered);

        $this->assertSame("Line\nbreak", $parsed['title']);
        $this->assertSame('Quote " and slash / kept', $parsed['message']);
    }

    public function testStrictReturnsNullForEmpty(): void
    {
        $this->assertNull(PushTemplateParser::parseStrict(''));
        $this->assertNull(PushTemplateParser::parseStrict('   '));
    }

    public function testStrictDefaultsMissingSubtitleToNull(): void
    {
        $parsed = PushTemplateParser::parseStrict('{"title":"t","message":"m"}');

        $this->assertSame(null, $parsed['subtitle']);
    }

    public function testLenientRecoversFromUnescapedQuotesInValue(): void
    {
        // This is the exact Sentry KANVAS-ECOSYSTEM-2KN payload shape: the
        // template wrapped {{ $title }} in literal quotes, so the rendered
        // message field contains unescaped quotes mid-value.
        $rendered = '{"title":"New Like","message":"Your AI creation "Using Neural Networks to Predict Climate" just got an upvote from bobby_ai118! "}';

        $parsed = PushTemplateParser::parseLenient($rendered);

        $this->assertSame('New Like', $parsed['title']);
        $this->assertSame(
            'Your AI creation "Using Neural Networks to Predict Climate" just got an upvote from bobby_ai118!',
            $parsed['message'],
        );
        $this->assertNull($parsed['subtitle']);
    }

    public function testLenientHandlesWellFormedJsonToo(): void
    {
        $rendered = '{"title":"A","message":"B","subtitle":"C"}';

        $parsed = PushTemplateParser::parseLenient($rendered);

        $this->assertSame([
            'title' => 'A',
            'message' => 'B',
            'subtitle' => 'C',
        ], $parsed);
    }

    public function testLenientReturnsEmptyStringsWhenKeysMissing(): void
    {
        $parsed = PushTemplateParser::parseLenient('{}');

        $this->assertSame([
            'title' => '',
            'message' => '',
            'subtitle' => null,
        ], $parsed);
    }

    public function testLenientHandlesValueWithCommaInside(): void
    {
        $rendered = '{"title":"Hi","message":"a, b, c"}';

        $parsed = PushTemplateParser::parseLenient($rendered);

        $this->assertSame('Hi', $parsed['title']);
        $this->assertSame('a, b, c', $parsed['message']);
    }

    public function testLenientHandlesTitleSubtitleMessageOrderWithQuotesInMessage(): void
    {
        // Real-world template shape: title, subtitle, message — with a value
        // that contains unescaped quotes (entity title interpolated raw).
        $rendered = '{ "title": "Hello John", "subtitle": "New entity has been created", "message": "Jane created a new entity: My "Cool" Entity" }';

        $parsed = PushTemplateParser::parseLenient($rendered);

        $this->assertSame('Hello John', $parsed['title']);
        $this->assertSame('New entity has been created', $parsed['subtitle']);
        $this->assertSame('Jane created a new entity: My "Cool" Entity', $parsed['message']);
    }

    public function testLenientPreservesOrderingRegardlessOfFieldOrder(): void
    {
        $rendered = '{"subtitle":"sub","message":"msg","title":"ttl"}';

        $parsed = PushTemplateParser::parseLenient($rendered);

        $this->assertSame('ttl', $parsed['title']);
        $this->assertSame('msg', $parsed['message']);
        $this->assertSame('sub', $parsed['subtitle']);
    }
}
