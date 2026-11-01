<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class TwilioHandlersTest extends TestCase
{
    private const array CREDENTIALS = [
        'account_sid' => 'ACconnected',
        'auth_token' => 'connected-token',
    ];

    private Apps $testApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $this->company->del(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);

        parent::tearDown();
    }

    public function testConnectingWritesTheCompanyAndLeavesTheAppAlone(): void
    {
        $appSid = $this->testApp->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);

        $this->assertTrue($this->handler(valid: true)->setup());

        $this->assertSame('ACconnected', $this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value));
        $this->assertSame('connected-token', $this->company->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value));
        $this->assertSame($appSid, $this->testApp->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value));
    }

    public function testDisconnectingRemovesTheCredentials(): void
    {
        $handler = $this->handler(valid: true);
        $handler->setup();

        $handler->teardown();

        $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value));
        $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_AUTH_TOKEN->value));
    }

    public function testRejectedCredentialsAreNotSaved(): void
    {
        $this->expectException(Exception::class);

        try {
            $this->handler(valid: false)->setup();
        } finally {
            $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value));
        }
    }

    private function handler(bool $valid): TwilioHandlers
    {
        $handler = new class (
            $this->testApp,
            $this->company,
            new Regions(),
            self::CREDENTIALS
        ) extends TwilioHandlers {
            public bool $valid = true;

            protected function validateCredentials(string $accountSid, string $authToken): bool
            {
                return $this->valid;
            }
        };
        $handler->valid = $valid;

        return $handler;
    }
}
