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
        'from_number' => '+1 (809) 555-0100',
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
        $this->company->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

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
        $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
    }

    public function testConnectingSavesTheFromNumberInE164(): void
    {
        $this->handler(valid: true)->setup();

        $this->assertSame('+18095550100', $this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
    }

    public function testFromNumberNotOnTheAccountIsRejectedAndNothingSaved(): void
    {
        $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, '+12722917870');

        try {
            $this->handler(valid: true, ownsNumber: false)->setup();
            $this->fail('Expected a number that is not on the account to be rejected');
        } catch (Exception $e) {
            $this->assertStringContainsString('+18095550100', $e->getMessage());
        }

        $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_ACCOUNT_SID->value));
        $this->assertSame('+12722917870', $this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
    }

    public function testSwitchingAccountsWithoutANumberClearsTheOldOne(): void
    {
        $this->company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'ACprevious');
        $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, '+12722917870');

        $this->handler(valid: true, credentials: ['account_sid' => 'ACconnected', 'auth_token' => 'connected-token'])->setup();

        $this->assertEmpty($this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
    }

    public function testReconnectingTheSameAccountWithoutANumberKeepsIt(): void
    {
        $this->company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'ACconnected');
        $this->company->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, '+18095550100');

        $this->handler(valid: true, credentials: ['account_sid' => 'ACconnected', 'auth_token' => 'connected-token'])->setup();

        $this->assertSame('+18095550100', $this->company->get(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value));
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

    /**
     * @param array<string, string>|null $credentials
     */
    private function handler(bool $valid, bool $ownsNumber = true, ?array $credentials = null): TwilioHandlers
    {
        $handler = new class (
            $this->testApp,
            $this->company,
            new Regions(),
            $credentials ?? self::CREDENTIALS
        ) extends TwilioHandlers {
            public bool $valid = true;
            public bool $ownsNumber = true;

            protected function validateCredentials(string $accountSid, string $authToken): bool
            {
                return $this->valid;
            }

            protected function ownsPhoneNumber(string $accountSid, string $authToken, string $phoneNumber): bool
            {
                return $this->ownsNumber;
            }
        };
        $handler->valid = $valid;
        $handler->ownsNumber = $ownsNumber;

        return $handler;
    }
}
