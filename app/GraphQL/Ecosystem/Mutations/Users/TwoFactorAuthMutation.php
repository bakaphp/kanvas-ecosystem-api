<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Enums\UserConfigEnum;

use function Sentry\captureException;

use Throwable;

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

        try {
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
        } catch (Throwable $e) {
            //throw new ValidationException($e->getMessage());
            Log::error($e->getMessage());
            captureException($e);

            return false;
        }

        return false;
    }

    public function setToggleTwoFactorAuthIn30Days($rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $key = UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value . '-' . $user->getCurrentDeviceId();

        if ($request['active']) {
            return $user->set($key, (int) $request['active']);
        }

        return $user->del($key);
    }
}
