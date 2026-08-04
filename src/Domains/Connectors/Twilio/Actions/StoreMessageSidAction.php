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
        if ($this->message->exists) {
            new RecordMessageAttemptAction($this->message)->execute($providerResponse);
        }

        $sid = data_get($providerResponse, 'messages.0.sid');
        $errorCode = data_get($providerResponse, 'twilio_error_code');
        $errorMessage = data_get($providerResponse, 'error');

        if (is_int($errorCode) || (is_string($errorCode) && $errorCode !== '')) {
            $this->message->set(CustomFieldEnum::LAST_ERROR_CODE->value, (string) $errorCode);
            $this->message->set(CustomFieldEnum::CURRENT_STATUS->value, 'failed');
            $this->message->set(CustomFieldEnum::LAST_STATUS_AT->value, now()->toAtomString());
        }
        if (is_string($errorMessage) && $errorMessage !== '') {
            $this->message->set(CustomFieldEnum::LAST_ERROR_MESSAGE->value, $errorMessage);
        }

        if (! is_string($sid) || $sid === '') {
            return null;
        }

        $this->message->set(CustomFieldEnum::MESSAGE_SID->value, $sid);

        $status = data_get($providerResponse, 'messages.0.status');
        if (is_string($status) && $status !== '') {
            $this->message->set(CustomFieldEnum::CURRENT_STATUS->value, $status);
            $this->message->set(CustomFieldEnum::LAST_STATUS_AT->value, now()->toAtomString());
        }

        return $sid;
    }
}
