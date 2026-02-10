<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Enums\UserConfigEnum;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\withScope;

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
        $user = auth()->user();
        $phoneNumber = $user->getAppProfile($app)->getTwoStepPhoneNumber();

        $skipVerification = (array) ($app->get(ConfigurationEnum::TWILIO_VERIFICATION_SKIP_USERS->value) ?? []);
        if (in_array($user->getId(), $skipVerification)) {
            return true;
        }

        $rateLimitKey = 'two-factor-send:' . $app->getId() . ':' . $user->getId();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            withScope(function (Scope $scope) use ($user, $app, $phoneNumber): void {
                $scope->setLevel(Severity::warning());
                $scope->setContext('2fa_rate_limit', [
                    'user_id' => $user->getId(),
                    'email' => $user->email,
                    'phone' => $phoneNumber,
                    'app_id' => $app->getId(),
                    'app_name' => $app->name,
                    'ip' => request()->ip(),
                ]);
                captureMessage('2FA SMS rate limit exceeded - possible abuse');
            });

            throw new ValidationException(
                "Too many verification attempts. Please try again in {$seconds} seconds."
            );
        }

        RateLimiter::hit($rateLimitKey, 600);

        $twilio = Client::getInstance($app);

        $verification = $twilio->verify
            ->v2
            ->services($app->get(ConfigurationEnum::TWILIO_VERIFICATION_SID->value))
            ->verifications
            ->create('+' . $phoneNumber, 'sms');

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

        $key = $user->getCurrentDeviceId() ? UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value . '-' . $user->getCurrentDeviceId() : UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value;

        if ($request['active']) {
            return $user->set($key, (int) $request['active']);
        }

        return $user->del($key);
    }
}
