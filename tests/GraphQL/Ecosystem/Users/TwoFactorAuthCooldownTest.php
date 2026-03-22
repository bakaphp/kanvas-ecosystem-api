<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use App\GraphQL\Ecosystem\Mutations\Users\TwoFactorAuthMutation;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class TwoFactorAuthCooldownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        RateLimiter::clear('two-factor-send:' . $app->getId() . ':' . $user->getId());
        RateLimiter::clear('two-factor-verify:' . $app->getId() . ':' . $user->getId());
        RateLimiter::clear('two-factor-send-cooldown:' . $app->getId() . ':' . $user->getId());

        $app->del(ConfigurationEnum::TWILIO_2FA_SEND_COOLDOWN_SECONDS->value);

        $user->getAppProfile($app)->update([
            'two_step_phone_number' => '3055550100',
        ]);
    }

    public function testItBlocksVerificationCodeResendDuringCooldownViaMutation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $app->set(ConfigurationEnum::TWILIO_2FA_SEND_COOLDOWN_SECONDS->value, 60);

        $cooldownKey = 'two-factor-send-cooldown:' . $app->getId() . ':' . $user->getId();
        RateLimiter::hit($cooldownKey, 60);

        $mutation = new TwoFactorAuthMutation();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Verification code already sent. Please wait');

        $mutation->sendVerificationCode(null, []);
    }

    public function testItBlocksVerificationCodeResendDuringCooldownViaGraphql(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $app->set(ConfigurationEnum::TWILIO_2FA_SEND_COOLDOWN_SECONDS->value, 60);

        $cooldownKey = 'two-factor-send-cooldown:' . $app->getId() . ':' . $user->getId();
        RateLimiter::hit($cooldownKey, 60);

        $this->graphQL('
            mutation {
                sendVerificationCode
            }
        ')
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Verification code already sent. Please wait 60 seconds before requesting a new code.',
                ],
            ],
        ]);
    }
}
