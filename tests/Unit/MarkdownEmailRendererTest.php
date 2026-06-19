<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownEmailRendererTest extends TestCase
{
    public function testConvertsBoldToStrong(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml('Hello **world**');

        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    public function testConvertsBulletsToListItems(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml("- one\n- two");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
        $this->assertStringNotContainsString('- one', $html);
    }

    public function testConvertsLinks(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml('[Kanvas](https://kanvas.dev)');

        $this->assertStringContainsString('<a href="https://kanvas.dev">Kanvas</a>', $html);
    }

    public function testConvertsHeadings(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml('## Title');

        $this->assertStringContainsString('<h2>Title</h2>', $html);
    }

    public function testSingleNewlineBecomesBreak(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml("Best regards,\nThe Team");

        $this->assertStringContainsString('<br>', $html);
        $this->assertStringContainsString('Best regards,', $html);
        $this->assertStringContainsString('The Team', $html);
    }

    public function testIsIdempotentOnHtmlInput(): void
    {
        $alreadyHtml = '<p>Hello <strong>world</strong></p>';

        $this->assertSame($alreadyHtml, MarkdownEmailRenderer::toEmailHtml($alreadyHtml));
    }

    public function testEmptyStringPassesThrough(): void
    {
        $this->assertSame('', MarkdownEmailRenderer::toEmailHtml(''));
        $this->assertSame('', MarkdownEmailRenderer::toEmailHtml('   '));
    }

    public function testPlainTextIsWrappedInParagraph(): void
    {
        $html = MarkdownEmailRenderer::toEmailHtml('Just a plain sentence.');

        $this->assertStringContainsString('<p>Just a plain sentence.</p>', $html);
    }
}
