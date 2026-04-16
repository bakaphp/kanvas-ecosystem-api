<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\EmailVerification as EmailVerificationService;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        (new SyncEmailTemplateAction($app, $user))->execute(overWrite: false);

        $app->set(AppSettingsEnums::SEND_EMAIL_VERIFICATION->getValue(), true);
        $app->set(AppSettingsEnums::EMAIL_VERIFICATION_LINK_URL->getValue(), $app->url . '/verify-email');
    }

    public function testVerifyEmailWithValidToken(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $this->unverifyUser($user, $app);

        $token = (new EmailVerificationService($app))->generateToken($user);

        $this->graphQL('
            mutation VerifyEmail($token: String!) {
                verifyEmail(token: $token)
            }
        ', ['token' => $token])
            ->assertSuccessful()
            ->assertJson(['data' => ['verifyEmail' => true]]);

        $profile = UsersAssociatedApps::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();

        $this->assertEquals(1, $profile->is_verified);
        $this->assertNotNull($profile->email_verified_at);
    }

    public function testVerifyEmailAlreadyVerified(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $profile = UsersAssociatedApps::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();
        $profile->is_verified = 1;
        $profile->saveOrFail();

        $token = (new EmailVerificationService($app))->generateToken($user);

        $this->graphQL('
            mutation VerifyEmail($token: String!) {
                verifyEmail(token: $token)
            }
        ', ['token' => $token])
            ->assertSuccessful()
            ->assertJson(['data' => ['verifyEmail' => true]]);
    }

    public function testVerifyEmailWithInvalidToken(): void
    {
        $this->graphQL('
            mutation VerifyEmail($token: String!) {
                verifyEmail(token: $token)
            }
        ', ['token' => 'invalid-token-here'])
            ->assertGraphQLErrorMessage('Verification link is invalid.');
    }

    public function testVerifyEmailWithExpiredToken(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $app->set(AppSettingsEnums::EMAIL_VERIFICATION_LINK_TTL_HOURS->getValue(), 0);

        $token = (new EmailVerificationService($app))->generateToken($user);

        $app->del(AppSettingsEnums::EMAIL_VERIFICATION_LINK_TTL_HOURS->getValue());

        $this->graphQL('
            mutation VerifyEmail($token: String!) {
                verifyEmail(token: $token)
            }
        ', ['token' => $token])
            ->assertGraphQLErrorMessage('Verification link has expired.');
    }

    public function testResendVerificationEmail(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $this->unverifyUser($user, $app);

        $this->graphQL('
            mutation {
                resendVerificationEmail
            }
        ')
            ->assertSuccessful()
            ->assertJson(['data' => ['resendVerificationEmail' => true]]);
    }

    public function testResendVerificationEmailSkipsVerifiedUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $profile = UsersAssociatedApps::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();
        $profile->is_verified = 1;
        $profile->saveOrFail();

        $this->graphQL('
            mutation {
                resendVerificationEmail
            }
        ')
            ->assertSuccessful()
            ->assertJson(['data' => ['resendVerificationEmail' => false]]);
    }

    private function unverifyUser(Users $user, Apps $app): void
    {
        $profile = UsersAssociatedApps::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->first();
        $profile->is_verified = 0;
        $profile->email_verified_at = null;
        $profile->saveOrFail();
    }
}
