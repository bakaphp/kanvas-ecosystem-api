<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use App\GraphQL\Ecosystem\Mutations\Users\TwoFactorAuthMutation;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class TwoFactorAuthCooldownTest extends TestCase
{
    public function testItBlocksVerificationCodeResendDuringCooldown(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $userApp = $user->getAppProfile($app);

        $userApp->update([
            'two_step_phone_number' => '3055550100',
        ]);

        $app->set(ConfigurationEnum::TWILIO_2FA_SEND_COOLDOWN_SECONDS->value, 60);

        $cooldownKey = 'two-factor-send-cooldown:' . $app->getId() . ':' . $user->getId();
        RateLimiter::hit($cooldownKey, 60);

        $mutation = new TwoFactorAuthMutation();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Verification code already sent. Please wait');

        $mutation->sendVerificationCode(null, []);
    }
}
