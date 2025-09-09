<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Resolvers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\VerificationChannelEnum;
use Kanvas\Users\Models\Users;

class DestinationResolver
{
    public function __construct(
        protected Users $user,
        protected Apps $app,
        protected VerificationChannelEnum $channel
    ) {
    }

    public function getDestination(): string
    {
        $userAppProfile = $this->user->getAppProfile($this->app);

        return match ($this->channel) {
            VerificationChannelEnum::SMS => '+' . $userAppProfile->getTwoStepPhoneNumber(),
            VerificationChannelEnum::EMAIL => $userAppProfile->email,
        };
    }
}
