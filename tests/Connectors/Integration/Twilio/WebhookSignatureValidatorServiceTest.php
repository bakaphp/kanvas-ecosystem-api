<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Services\WebhookSignatureValidatorService;
use Mockery;
use Tests\TestCaseUnit;
use Twilio\Security\RequestValidator;

final class WebhookSignatureValidatorServiceTest extends TestCaseUnit
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

        $this->assertTrue(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(),
            $this->app(),
            self::URL,
        ));
    }

    public function testRejectsInvalidSignature(): void
    {
        $request = $this->request($this->parameters());
        $request->headers->set('X-Twilio-Signature', 'invalid');

        $this->assertFalse(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(),
            $this->app(),
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

        $this->assertFalse(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(),
            $this->app(),
            self::URL,
        ));
    }

    /**
     * A company with no Twilio account of its own texts through the app's, so that is the account
     * whose token signs what Twilio posts back.
     */
    public function testACompanyOnTheAppsAccountIsCheckedAgainstTheAppsToken(): void
    {
        $parameters = $this->parameters();
        $request = $this->request($parameters);
        $request->headers->set(
            'X-Twilio-Signature',
            new RequestValidator(self::AUTH_TOKEN)->computeSignature(self::URL, $parameters),
        );

        $this->assertTrue(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(null, null),
            $this->app(self::ACCOUNT_SID, self::AUTH_TOKEN),
            self::URL,
        ));
    }

    public function testTheCompanysOwnAccountWinsOverTheApps(): void
    {
        $parameters = $this->parameters(['AccountSid' => 'AC-app']);
        $request = $this->request($parameters);
        $request->headers->set(
            'X-Twilio-Signature',
            new RequestValidator('app-token')->computeSignature(self::URL, $parameters),
        );

        $this->assertFalse(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(),
            $this->app('AC-app', 'app-token'),
            self::URL,
        ));
    }

    public function testRejectsWhenNeitherTheCompanyNorTheAppHasCredentials(): void
    {
        $parameters = $this->parameters();
        $request = $this->request($parameters);
        $request->headers->set(
            'X-Twilio-Signature',
            new RequestValidator(self::AUTH_TOKEN)->computeSignature(self::URL, $parameters),
        );

        $this->assertFalse(WebhookSignatureValidatorService::validate(
            $request,
            $this->company(null, null),
            $this->app(),
            self::URL,
        ));
    }

    private function company(?string $sid = self::ACCOUNT_SID, ?string $token = self::AUTH_TOKEN): Companies
    {
        $company = Mockery::mock(Companies::class)->makePartial();
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn($sid);
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_AUTH_TOKEN->value)
            ->andReturn($token);

        return $company;
    }

    private function app(?string $sid = null, ?string $token = null): Apps
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_ACCOUNT_SID->value)
            ->andReturn($sid);
        $app->shouldReceive('get')
            ->with(ConfigurationEnum::TWILIO_AUTH_TOKEN->value)
            ->andReturn($token);

        return $app;
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
