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

        if ($user->get(UserConfigEnum::TWO_FACTOR_AUTH_DISABLED->value)) {
            return false;
        }

        $phoneNumber = $user->getAppProfile($app)->getTwoStepPhoneNumber();

        $skipVerification = (array) ($app->get(ConfigurationEnum::TWILIO_VERIFICATION_SKIP_USERS->value) ?? []);
        if (in_array($user->getId(), $skipVerification)) {
            return true;
        }

        $sendRateLimit = (int) ($app->get(ConfigurationEnum::TWILIO_2FA_SEND_RATE_LIMIT->value) ?: 2);
        $rateLimitDecaySeconds = (int) ($app->get(ConfigurationEnum::TWILIO_2FA_SEND_RATE_LIMIT_DECAY->value) ?: 28800); // 8 hours

        $rateLimitKey = 'two-factor-send:' . $app->getId() . ':' . $user->getId();
        if (RateLimiter::tooManyAttempts($rateLimitKey, $sendRateLimit)) {
            $this->logRateLimitExceeded('2FA SMS send rate limit exceeded - possible abuse', $user, $app, $phoneNumber);

            throw new ValidationException(
                'Too many verification attempts. Please try again later.'
            );
        }

        RateLimiter::hit($rateLimitKey, $rateLimitDecaySeconds);

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
    public function verifyCode(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $code = $request['code'];
        $userApp = $user->getAppProfile($app);

        $verifyRateLimit = (int) ($app->get(ConfigurationEnum::TWILIO_2FA_VERIFY_RATE_LIMIT->value) ?: 2);
        $rateLimitDecaySeconds = (int) ($app->get(ConfigurationEnum::TWILIO_2FA_VERIFY_RATE_LIMIT_DECAY->value) ?: 28800); // 8 hours

        $rateLimitKey = 'two-factor-verify:' . $app->getId() . ':' . $user->getId();
        if (RateLimiter::tooManyAttempts($rateLimitKey, $verifyRateLimit)) {
            $this->logRateLimitExceeded(
                '2FA verify rate limit exceeded - brute force attempt',
                $user,
                $app,
                $userApp->getTwoStepPhoneNumber()
            );

            throw new ValidationException(
                'Too many verification attempts. Please try again later.'
            );
        }

        RateLimiter::hit($rateLimitKey, $rateLimitDecaySeconds);

        $twilio = Client::getInstance($app);

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

                RateLimiter::clear($rateLimitKey);

                return true;
            }
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            captureException($e);

            return false;
        }

        withScope(function (Scope $scope) use ($user, $app): void {
            $scope->setLevel(Severity::warning());
            $scope->setContext('2fa_verify_failed', [
                'user_id' => $user->getId(),
                'email' => $user->email,
                'app_id' => $app->getId(),
                'app_name' => $app->name,
                'ip' => request()->ip(),
            ]);
            captureMessage('2FA verification failed - invalid code');
        });

        return false;
    }

    public function setToggleTwoFactorAuthIn30Days(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $key = $user->getCurrentDeviceId() ? UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value . '-' . $user->getCurrentDeviceId() : UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value;

        if ($request['active']) {
            return $user->set($key, (int) $request['active']);
        }

        return $user->del($key);
    }

    private function logRateLimitExceeded(
        string $message,
        mixed $user,
        Apps $app,
        ?string $phoneNumber = null
    ): void {
        withScope(function (Scope $scope) use ($message, $user, $app, $phoneNumber): void {
            $scope->setLevel(Severity::warning());
            $scope->setContext('2fa_rate_limit', [
                'user_id' => $user->getId(),
                'email' => $user->email,
                'phone' => $phoneNumber,
                'app_id' => $app->getId(),
                'app_name' => $app->name,
                'ip' => request()->ip(),
            ]);
            captureMessage($message);
        });
    }
}
