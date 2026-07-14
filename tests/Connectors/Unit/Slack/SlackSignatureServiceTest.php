<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Slack;

use Kanvas\Connectors\Slack\Services\SlackSignatureService;
use Tests\TestCase;

final class SlackSignatureServiceTest extends TestCase
{
    private const string SECRET = 'shhh';
    private const string BODY = '{"type":"event_callback"}';

    public function testAcceptsAGenuineSignature(): void
    {
        $timestamp = (string) time();

        $this->assertTrue(SlackSignatureService::isValid(
            self::BODY,
            $timestamp,
            $this->sign(self::BODY, $timestamp),
            self::SECRET,
        ));
    }

    public function testRejectsTheWrongSecret(): void
    {
        $timestamp = (string) time();

        $this->assertFalse(SlackSignatureService::isValid(
            self::BODY,
            $timestamp,
            $this->sign(self::BODY, $timestamp, 'not-the-secret'),
            self::SECRET,
        ));
    }

    public function testRejectsATamperedBody(): void
    {
        $timestamp = (string) time();

        $this->assertFalse(SlackSignatureService::isValid(
            '{"type":"tampered"}',
            $timestamp,
            $this->sign(self::BODY, $timestamp),
            self::SECRET,
        ));
    }

    public function testRejectsAReplayOutsideTheWindow(): void
    {
        $timestamp = (string) (time() - 301);

        $this->assertFalse(SlackSignatureService::isValid(
            self::BODY,
            $timestamp,
            $this->sign(self::BODY, $timestamp),
            self::SECRET,
        ));
    }

    public function testRejectsMissingHeaders(): void
    {
        $this->assertFalse(SlackSignatureService::isValid(self::BODY, '', '', self::SECRET));
    }

    private function sign(string $body, string $timestamp, ?string $secret = null): string
    {
        return 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, $secret ?? self::SECRET);
    }
}
