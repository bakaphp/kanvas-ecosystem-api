<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\VerificationChannelEnum;
use Kanvas\Connectors\Twilio\Resolvers\DestinationResolver;
use Kanvas\Connectors\Twilio\Services\VerificationService;
use Kanvas\Users\Enums\UserConfigEnum;

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
        $request = $request['input'];
        $channel = strtolower($request['channel'] ?? 'sms');
        $channelEnum = VerificationChannelEnum::from($channel);
        $verificationService = new VerificationService($app, new DestinationResolver($user, $app, $channelEnum));
        return $verificationService->start($channelEnum);
    }

    /**
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress MixedMethodCall
     */
    public function verifyCode($rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $request = $request['input'];
        $channel = strtolower($request['channel'] ?? 'sms');
        $channelEnum = VerificationChannelEnum::from($channel);
        $verificationService = new VerificationService($app, new DestinationResolver($user, $app, $channelEnum));
        return $verificationService->check($user, $channelEnum, $request['code']);
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
