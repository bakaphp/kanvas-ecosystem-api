<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Tests\TestCase;

class SessionChannelServiceTest extends TestCase
{
    private const PUSHER_CHANNEL_REGEX = '/^[A-Za-z0-9_=@,.;\-]+$/';

    public function testSmsSlugIsDigitsOnlyWhenInputHasParensAndSpaces(): void
    {
        // Regression: Sentry KANVAS-ECOSYSTEM-5E6 — Pusher rejected
        // 'twilio-(661) 644-8670' because parens/spaces are invalid.
        $slug = SessionChannelService::createChannelSlug('sms', '(661) 644-8670');

        $this->assertSame('twilio-6616448670', $slug);
        $this->assertMatchesRegularExpression(self::PUSHER_CHANNEL_REGEX, $slug);
    }

    public function testSmsSlugStripsLeadingPlusOneFromE164(): void
    {
        $slug = SessionChannelService::createChannelSlug('sms', '+13055551234');

        $this->assertSame('twilio-3055551234', $slug);
    }

    public function testSmsSlugIsUnchangedForAlreadyDigitOnlyInput(): void
    {
        $slug = SessionChannelService::createChannelSlug('sms', '3055551234');

        $this->assertSame('twilio-3055551234', $slug);
    }

    public function testWhatsappSlugStripsNonDigitCharacters(): void
    {
        $slug = SessionChannelService::createChannelSlug('whatsapp', '(305) 555-1234');

        $this->assertSame('wa-chat-3055551234-at-swhatsappnet', $slug);
        $this->assertMatchesRegularExpression(self::PUSHER_CHANNEL_REGEX, $slug);
    }

    public function testRespondioSlugStripsNonDigitCharacters(): void
    {
        $slug = SessionChannelService::createChannelSlug('respondio', '+1 (305) 555-1234');

        $this->assertSame('respondio-3055551234', $slug);
        $this->assertMatchesRegularExpression(self::PUSHER_CHANNEL_REGEX, $slug);
    }

    public function testEmailSlugReplacesAtAndDot(): void
    {
        $slug = SessionChannelService::createChannelSlug('email', 'jane@example.com');

        $this->assertSame('email-jane-at-example-dot-com', $slug);
        $this->assertMatchesRegularExpression(self::PUSHER_CHANNEL_REGEX, $slug);
    }

    public function testAiAssistSlugPassesIdThrough(): void
    {
        $slug = SessionChannelService::createChannelSlug('ai-assist', 'abc-123');

        $this->assertSame('ai-assist-abc-123', $slug);
    }
}
