<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Social\Messages\Models\Message;

class StoreMessageSidAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(array $providerResponse): ?string
    {
        $sid = data_get($providerResponse, 'messages.0.sid');

        if (! is_string($sid) || $sid === '') {
            return null;
        }

        $this->message->set(CustomFieldEnum::MESSAGE_SID->value, $sid);

        return $sid;
    }
}
