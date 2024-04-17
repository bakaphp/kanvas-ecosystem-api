<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;

class TwoFactorAuthMutation
{
    /**
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress MixedMethodCall
     */
    public function sendVerificationCode(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $twilio = Client::getInstance($app);
        $user = auth()->user();

        $verification = $twilio->verify
            ->v2
            ->services($app->get(ConfigurationEnum::TWILIO_VERIFICATION_SID->value))
            ->verifications
            ->create('+' . $user->getAppProfile($app)->getTwoStepPhoneNumber(), 'sms');

        return $verification->status === 'pending';
    }

    /**
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress MixedMethodCall
     */
    public function verifyCode($rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $twilio = Client::getInstance($app);
        $user = auth()->user();
        $code = $request['code'];
        $userApp = $user->getAppProfile($app);

        $checkCode = $twilio->verify
                ->v2
                ->services($app->get(ConfigurationEnum::TWILIO_VERIFICATION_SID->value))
                ->verificationChecks
                ->create(
                    [
                        'to' => '+' . $userApp->getTwoStepPhoneNumber(),
                        'code' => $code,
                    ]
                );

        if ($checkCode->valid === true) {
            $userApp->update([
                'phone_verified_at' => Carbon::now()->toDateTimeString(),
            ]);

            return true;
        }

        return false;
    }
}
