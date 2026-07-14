<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Slack;

use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Tests\TestCase;

final class SlackMarkdownServiceTest extends TestCase
{
    public function testBoldBecomesSingleAsterisks(): void
    {
        $this->assertSame('*deal closed*', SlackMarkdownService::toMrkdwn('**deal closed**'));
        $this->assertSame('*deal closed*', SlackMarkdownService::toMrkdwn('__deal closed__'));
    }

    public function testLinksBecomeSlackLinks(): void
    {
        $this->assertSame(
            'see <https://kanvas.dev|the deal>',
            SlackMarkdownService::toMrkdwn('see [the deal](https://kanvas.dev)')
        );
    }

    public function testHeadingsBecomeBoldLines(): void
    {
        $this->assertSame("*Summary*\nthree deals", SlackMarkdownService::toMrkdwn("## Summary\nthree deals"));
    }

    public function testCodeBlocksSurviveVerbatim(): void
    {
        $markdown = "**note**\n```\n**not bold in here**\n```";

        $this->assertSame("*note*\n```\n**not bold in here**\n```", SlackMarkdownService::toMrkdwn($markdown));
    }

    public function testInboundLinksBecomeMarkdown(): void
    {
        $this->assertSame(
            'see [the deal](https://kanvas.dev)',
            SlackMarkdownService::fromMrkdwn('see <https://kanvas.dev|the deal>')
        );
        $this->assertSame(
            'raw https://kanvas.dev',
            SlackMarkdownService::fromMrkdwn('raw <https://kanvas.dev>')
        );
    }

    public function testInboundMentionsAndChannelsAreDecoded(): void
    {
        $this->assertSame('ask @carla', SlackMarkdownService::fromMrkdwn('ask <@U123|carla>'));
        $this->assertSame('ask @U123', SlackMarkdownService::fromMrkdwn('ask <@U123>'));
        $this->assertSame('in #general', SlackMarkdownService::fromMrkdwn('in <#C123|general>'));
        $this->assertSame('@here look', SlackMarkdownService::fromMrkdwn('<!here> look'));
    }

    public function testInboundEntitiesAreUnescaped(): void
    {
        $this->assertSame('R&D < 5 > 2', SlackMarkdownService::fromMrkdwn('R&amp;D &lt; 5 &gt; 2'));
    }
}
∑