<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Tests\TestCase;

class RegistrationSpamProtectionTest extends TestCase
{
    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->del(AppSettingsEnums::VALIDATE_EMAIL_DNS->getValue());

        parent::tearDown();
    }

    public function testRegistrationRateLimitByIp(): void
    {
        $app = app(Apps::class);

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');

        for ($i = 0; $i < 10; $i++) {
            $email = fake()->unique()->safeEmail();
            $password = fake()->password(9);

            $this->graphQL(/** @lang GraphQL */ '
                mutation register($data: RegisterInput!) {
                    register(data: $data) {
                        user { email }
                        token { token }
                    }
                }
            ', [
                'data' => [
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $password,
                ],
            ])->assertSuccessful();
        }

        $email = fake()->unique()->safeEmail();
        $password = fake()->password(9);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                    user { email }
                    token { token }
                }
            }
        ', [
            'data' => [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ]);

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected rate limit error but got none');
        $this->assertStringContainsString(
            'Too many registration attempts',
            $errors[0]['message'] ?? $errors[0]['extensions']['debugMessage'] ?? ''
        );

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');
    }

    public function testRegistrationWithDnsEmailValidationRejectsInvalidDomain(): void
    {
        $app = app(Apps::class);
        $app->set(AppSettingsEnums::VALIDATE_EMAIL_DNS->getValue(), true);

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');

        $password = fake()->password(9);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                    user { email }
                    token { token }
                }
            }
        ', [
            'data' => [
                'email' => 'test@thisisnotarealdomainthatexists999.com',
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ]);

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected validation error for invalid DNS domain');

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');
    }

    public function testRegistrationWithoutDnsValidationAcceptsAnyEmailFormat(): void
    {
        $app = app(Apps::class);
        $app->del(AppSettingsEnums::VALIDATE_EMAIL_DNS->getValue());

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');

        $password = fake()->password(9);

        $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                    user { email }
                    token { token }
                }
            }
        ', [
            'data' => [
                'email' => 'validformat@anydomain.xyz',
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ])->assertSuccessful();

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');
    }

    public function testRegistrationDnsValidationDisabledByDefault(): void
    {
        $app = app(Apps::class);
        $app->del(AppSettingsEnums::VALIDATE_EMAIL_DNS->getValue());

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');

        $email = fake()->unique()->safeEmail();
        $password = fake()->password(9);

        $this->graphQL(/** @lang GraphQL */ '
            mutation register($data: RegisterInput!) {
                register(data: $data) {
                    user { email }
                    token { token }
                }
            }
        ', [
            'data' => [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ])->assertSuccessful();

        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');
    }
}
