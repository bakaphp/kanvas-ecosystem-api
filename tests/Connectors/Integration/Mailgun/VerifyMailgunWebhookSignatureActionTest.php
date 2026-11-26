<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Tests\TestCase;

class VerifyMailgunWebhookSignatureActionTest extends TestCase
{
    private const string SIGNING_KEY = 'test-mailgun-webhook-signing-key';

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');

        $this->company = auth()->user()->getCurrentCompany();
        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, self::SIGNING_KEY);
    }

    public function testValidSignatureWithCompanyKeyPasses(): void
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . $token, self::SIGNING_KEY);

        $result = new VerifyMailgunWebhookSignatureAction(
            $this->kanvasApp,
            $this->company,
            $token,
            $timestamp,
            $signature,
        )->execute();

        $this->assertTrue($result);
    }

    public function testTheCompanysOwnKeyWinsOverTheApps(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'app-global-signing-key');

        try {
            $this->assertTrue($this->verifies(self::SIGNING_KEY));
            $this->assertFalse($this->verifies('app-global-signing-key'));
        } finally {
            $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');
        }
    }

    public function testACompanyWithNoKeyOfItsOwnIsCheckedAgainstTheApps(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'app-global-signing-key');
        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');

        try {
            $this->assertTrue($this->verifies('app-global-signing-key'));
        } finally {
            $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');
        }
    }

    private function verifies(string $signedWith): bool
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));

        return new VerifyMailgunWebhookSignatureAction(
            $this->kanvasApp,
            $this->company,
            $token,
            $timestamp,
            hash_hmac('sha256', $timestamp . $token, $signedWith),
        )->execute();
    }

    public function testInvalidSignatureFails(): void
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));

        $result = new VerifyMailgunWebhookSignatureAction(
            $this->kanvasApp,
            $this->company,
            $token,
            $timestamp,
            'tampered-signature',
        )->execute();

        $this->assertFalse($result);
    }

    public function testStaleTimestampFails(): void
    {
        $timestamp = (string) (time() - 3600);
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . $token, self::SIGNING_KEY);

        $result = new VerifyMailgunWebhookSignatureAction(
            $this->kanvasApp,
            $this->company,
            $token,
            $timestamp,
            $signature,
        )->execute();

        $this->assertFalse($result);
    }

    public function testMissingFieldsFail(): void
    {
        $result = new VerifyMailgunWebhookSignatureAction($this->kanvasApp, $this->company, '', '', '')->execute();

        $this->assertFalse($result);
    }

    public function testMissingSigningKeyFails(): void
    {
        $companyWithoutKey = Companies::factory()->create();

        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp . $token, self::SIGNING_KEY);

        $result = new VerifyMailgunWebhookSignatureAction(
            $this->kanvasApp,
            $companyWithoutKey,
            $token,
            $timestamp,
            $signature,
        )->execute();

        $this->assertFalse($result);
    }
}
