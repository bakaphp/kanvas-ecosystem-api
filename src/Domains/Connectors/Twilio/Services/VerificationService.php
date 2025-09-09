<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Enums\VerificationChannelEnum;
use Kanvas\Connectors\Twilio\Resolvers\DestinationResolver;
use Kanvas\Users\Models\Users;
use Throwable;
use Twilio\Rest\Client;

use function Sentry\captureException;

final class VerificationService
{
    public function __construct(
        private Apps $app,
        private DestinationResolver $resolver,
    ) {
    }

    private function twilio(): Client
    {
        return TwilioClient::getInstance($this->app);
    }

    private function serviceSid(): string
    {
        return (string) $this->app->get(ConfigurationEnum::TWILIO_VERIFICATION_SID->value);
    }

    public function start(VerificationChannelEnum $channel): bool
    {
        try {
            $to = $this->resolver->getDestination();

            $verification = $this->twilio()
                ->verify->v2
                ->services($this->serviceSid())
                ->verifications
                ->create($to, $channel->value);

            return $verification->sid ? true : false;
        } catch (Throwable $e) {
            captureException($e);

            return false;
        }
    }

    public function check(Users $user, VerificationChannelEnum $channel, string $code): bool
    {
        try {
            $to = $this->resolver->getDestination();

            $check = $this->twilio()
                ->verify->v2
                ->services($this->serviceSid())
                ->verificationChecks
                ->create([
                    'to' => $to,
                    'code' => $code,
                ]);

            if ($check->valid === true) {
                $this->markVerified($user, $channel);

                return true;
            }
        } catch (Throwable $e) {
            Log::error('[Verify:check] ' . $e->getMessage());
            captureException($e);

            return false;
        }

        return false;
    }

    private function markVerified(Users $user, VerificationChannelEnum $channel): void
    {
        $userApp = $user->getAppProfile($this->app);
        match ($channel) {
            VerificationChannelEnum::SMS =>
                $userApp->update(['phone_verified_at' => Carbon::now()->toDateTimeString()]),
            VerificationChannelEnum::EMAIL =>
                $userApp->update(['email_verified_at' => Carbon::now()->toDateTimeString()]),
        };
    }
}
