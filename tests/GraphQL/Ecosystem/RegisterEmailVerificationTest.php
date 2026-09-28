<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\EmailVerification;
use Kanvas\Enums\AppSettingsEnums;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\TestCase;

class RegisterEmailVerificationTest extends TestCase
{
    private function register(): TestResponse
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
                'email' => fake()->unique()->safeEmail(),
                'password' => $password,
                'password_confirmation' => $password,
            ],
        ]);
    }

    public function testRegisterReturnsATokenWhenVerificationIsNotRequired(): void
    {
        $response = $this->register();

        $this->assertNotNull(
            $response->json('data.register.token.token'),
            'Apps that never opted in must keep auto-login on signup.'
        );
    }

    /**
     * The setting is flipped on the real app because the resolver resolves its
     * own `app(Apps::class)` — so it is removed in a `finally` rather than in
     * `tearDown`, keeping the window where a parallel test could hit a gated
     * login down to this one request.
     */
    public function testRegisterWithholdsTheTokenWhenVerificationIsRequired(): void
    {
        $app = app(Apps::class);
        $app->set(AppSettingsEnums::REQUIRE_EMAIL_VERIFICATION->getValue(), true);

        try {
            $response = $this->register();
        } finally {
            $app->del(AppSettingsEnums::REQUIRE_EMAIL_VERIFICATION->getValue());
        }

        $response->assertSuccessful();
        $this->assertNotNull($response->json('data.register.user.email'), 'The account is still created.');
        $this->assertNull($response->json('data.register.token'), 'No session may be handed out before the address is proven.');
    }

    public function testIsRequiredForReadsThePerAppSwitch(): void
    {
        $this->assertFalse(EmailVerification::isRequiredFor(InMemorySettingsApp::withSettings()));
        $this->assertTrue(EmailVerification::isRequiredFor(
            InMemorySettingsApp::withSettings(['require_email_verification' => true])
        ));
        $this->assertFalse(EmailVerification::isRequiredFor(
            InMemorySettingsApp::withSettings(['require_email_verification' => false])
        ));
    }
}
