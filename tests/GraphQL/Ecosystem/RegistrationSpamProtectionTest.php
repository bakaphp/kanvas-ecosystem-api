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
        $app->del(AppSettingsEnums::BLOCKED_EMAIL_DOMAINS->getValue());

        parent::tearDown();
    }

    private function registerWithEmail(string $email)
    {
        $app = app(Apps::class);
        RateLimiter::clear('register_attempt:' . $app->getId() . ':127.0.0.1');

        $password = fake()->password(9);

        return $this->graphQL(/** @lang GraphQL */ '
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
    }

    public function testRegistrationBlocksKnownSpamDomain(): void
    {
        $response = $this->registerWithEmail('somerealname@web-library.net');

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected blocklisted-domain rejection but got none');
        $this->assertStringContainsString(
            'not allowed',
            $errors[0]['message'] ?? $errors[0]['extensions']['debugMessage'] ?? ''
        );
    }

    public function testRegistrationBlocksRandomizedLocalPart(): void
    {
        $response = $this->registerWithEmail('8rhpkhzq6sqwcx3@gmail.com');

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected randomized-local-part rejection but got none');
        $this->assertStringContainsString(
            'not allowed',
            $errors[0]['message'] ?? $errors[0]['extensions']['debugMessage'] ?? ''
        );
    }

    public function testRegistrationBlocksAppConfiguredDomain(): void
    {
        $app = app(Apps::class);
        $app->set(AppSettingsEnums::BLOCKED_EMAIL_DOMAINS->getValue(), 'badactor.io, spammy.example');

        $response = $this->registerWithEmail('jane@badactor.io');

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected app-configured blocked-domain rejection but got none');
    }

    public function testRegistrationAllowsNormalEmail(): void
    {
        $this->registerWithEmail('john.smith' . fake()->numberBetween(1, 99999) . '@gmail.com')
            ->assertSuccessful();
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
