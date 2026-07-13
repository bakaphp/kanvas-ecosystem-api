<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Kanvas\Connectors\Mercury\Actions\VerifyMercuryWebhookSignatureAction;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Enums\WebhookEventEnum;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * A false from the verifier makes the controller answer 401 — and Mercury does NOT retry 4xx. So a bug here
 * doesn't delay an event, it destroys it: no retry, no replay API. These tests are the guard on that.
 */
final class MercuryWebhookSignatureTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    private const string SECRET = 'whsec_test_abc123';
    private const string BODY = '{"id":"evt-1","resourceType":"transaction","resourceId":"txn-1"}';

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);
        $this->company->set(ConfigurationEnum::WEBHOOK_SECRET->value, self::SECRET);
    }

    public function testAValidSignatureIsAccepted(): void
    {
        $now = 1_800_000_000;

        $this->assertTrue($this->verify($this->sign($now), self::BODY, $now));
    }

    public function testATamperedBodyIsRejected(): void
    {
        $now = 1_800_000_000;
        $header = $this->sign($now);

        // The signature covers the body, so changing a single character must invalidate it — otherwise anyone
        // who intercepted one delivery could rewrite the amount.
        $this->assertFalse(
            $this->verify($header, '{"id":"evt-1","resourceType":"transaction","resourceId":"txn-EVIL"}', $now)
        );
    }

    public function testAWrongSecretIsRejected(): void
    {
        $now = 1_800_000_000;
        $forged = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . self::BODY, 'not-the-secret');

        $this->assertFalse($this->verify($forged, self::BODY, $now));
    }

    public function testAStaleTimestampIsRejected(): void
    {
        $signedAt = 1_800_000_000;
        $header = $this->sign($signedAt);

        // Replay protection: a captured request replayed 10 minutes later must not be accepted, even though
        // its signature is perfectly valid.
        $this->assertFalse($this->verify($header, self::BODY, $signedAt + 600));
        $this->assertTrue($this->verify($header, self::BODY, $signedAt + 60));
    }

    public function testAMissingOrMalformedHeaderIsRejected(): void
    {
        $now = 1_800_000_000;

        $this->assertFalse($this->verify('', self::BODY, $now));
        $this->assertFalse($this->verify('garbage', self::BODY, $now));
        $this->assertFalse($this->verify('t=abc,v1=xyz', self::BODY, $now));
        $this->assertFalse($this->verify('v1=' . hash_hmac('sha256', self::BODY, self::SECRET), self::BODY, $now));
    }

    public function testWithoutAStoredSecretNothingVerifies(): void
    {
        // Mercury returns the secret only on webhook creation. If we failed to capture it, we cannot verify
        // anything — and must not fall open.
        $this->company->set(ConfigurationEnum::WEBHOOK_SECRET->value, '');
        $now = 1_800_000_000;

        $this->assertFalse($this->verify($this->sign($now), self::BODY, $now));
    }

    public function testWeSubscribeToTheFiveEventsWeActuallyHandle(): void
    {
        $subscribed = WebhookEventEnum::subscribed();

        // transaction.updated is the one that must not be dropped: a card auth is CREATED as pending and only
        // UPDATED to sent when it settles, so the settlement arrives as an update or not at all.
        $this->assertContains('transaction.created', $subscribed);
        $this->assertContains('transaction.updated', $subscribed);
        $this->assertContains('checkingAccount.balance.updated', $subscribed);
        $this->assertContains('savingsAccount.balance.updated', $subscribed);
        $this->assertContains('creditAccount.balance.updated', $subscribed);

        // We model neither, so taking deliveries we'd discard just burns attempts and fills the log.
        $this->assertNotContains('treasuryAccount.balance.updated', $subscribed);
        $this->assertNotContains('investmentAccount.balance.updated', $subscribed);
    }

    private function sign(int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . self::BODY, self::SECRET);
    }

    private function verify(string $header, string $body, int $now): bool
    {
        return new VerifyMercuryWebhookSignatureAction(
            company: $this->company,
            signatureHeader: $header,
            rawBody: $body,
            now: $now,
        )->execute();
    }
}
