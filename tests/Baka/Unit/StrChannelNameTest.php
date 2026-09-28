<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCaseUnit;

final class StrChannelNameTest extends TestCaseUnit
{
    public function testKeepsAValidPusherChannelNameUntouched(): void
    {
        $name = 'agent-chat-31-9659-wa-chat-18095551234-at-swhatsappnet-31-9659';

        $this->assertSame($name, Str::sanitizeChannelName($name));
    }

    public function testKeepsTheCharactersPusherAllows(): void
    {
        $this->assertSame('a-Z_0=9@x,y.z;w', Str::sanitizeChannelName('a-Z_0=9@x,y.z;w'));
    }

    public function testReplacesThePlusOfAPlusAddressedEmailSlug(): void
    {
        $sanitized = Str::sanitizeChannelName(
            'agent-chat-31-9659-agent-1145-email-ap+caf_=acme-dot-ap-at-example-dot-com-31-9659'
        );

        $this->assertSame(
            'agent-chat-31-9659-agent-1145-email-ap-caf_=acme-dot-ap-at-example-dot-com-31-9659',
            $sanitized
        );
        $this->assertMatchesRegularExpression('/\A[-a-zA-Z0-9_=@,.;]+\z/', $sanitized);
    }

    public function testReplacesSpacesAndOtherRejectedCharacters(): void
    {
        $this->assertSame('a-b-c-d-e', Str::sanitizeChannelName('a b/c#d%e'));
    }

    public function testTruncatesNamesLongerThanPusherAllows(): void
    {
        $sanitized = Str::sanitizeChannelName('channel-' . str_repeat('x', 300));

        $this->assertSame(Str::BROADCAST_CHANNEL_MAX_LENGTH, strlen($sanitized));
        $this->assertStringStartsWith('channel-xxx', $sanitized);
    }

    public function testTruncatedNamesSharingAPrefixDoNotCollide(): void
    {
        $prefix = 'channel-' . str_repeat('x', 300);

        $this->assertNotSame(
            Str::sanitizeChannelName($prefix . '-one'),
            Str::sanitizeChannelName($prefix . '-two')
        );
    }
}
