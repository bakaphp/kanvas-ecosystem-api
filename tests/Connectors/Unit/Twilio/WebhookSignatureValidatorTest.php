<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Twilio;

use Illuminate\Http\Request;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Services\WebhookSignatureValidator;
use Mockery;
use Tests\TestCaseUnit;
use Twilio\Security\RequestValidator;

final class WebhookSignatureValidatorTest extends TestCaseUnit
{
    private const string URL = 'https://api.salesassist.io/v1/receiver/twilio-status';
    private const string ACCOUNT_SID = 'AC123';
    private const string AUTH_TOKEN = 'secret-token';

    public function testAcceptsValidTwilioSignature(): void
    {
        $parameters = $this->parameters();
        $request = $this->request($parameters);
        $request->headers->set(
            'X-Twilio-Signature',
            (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::URL, $parameters),
        );

        $this->assertTrue(WebhookSignatureValidator::validate(
            $request,
            $this->company(),
            self::URL,
        ));
    }

    public function testRejectsInvalidSignature(): void
    {
        $request = $this->request($this->parameters());
        $request->headers->set('X-Twilio-Signature', 'invalid');

        $this->assertFalse(WebhookSignatureValidator::validate(
            $request,
            $this->company(),
            self::URL,
        ));
    }

    public function testRejectsUnexpectedAccountSid(): void
    {
        $parameters = $this->parameters(['AccountSid' => 'AC-other']);
        $request = $this->request($parameters);
        $request->headers->set(
            'X-Twilio-Signature',
            (new RequestValidator(self::AUTH_TOKEN))->computeSignature(self::URL, $parameters),
        );

        $this->assertFalse(WebhookSignatureValidator::validate(
            $request,
            $this->company(),
            self::URL,
        ));
    }

    private function company(): Companies
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn(self::ACCOUNT_SID);
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_AUTH_TOKEN->value)
            ->andReturn(self::AUTH_TOKEN);

        return $company;
    }

    private function request(array $parameters): Request
    {
        return Request::create(
            self::URL,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($parameters),
        );
    }

    private function parameters(array $overrides = []): array
    {
        return array_merge([
            'AccountSid' => self::ACCOUNT_SID,
            'MessageSid' => 'SM123',
            'MessageStatus' => 'delivered',
        ], $overrides);
    }
}
