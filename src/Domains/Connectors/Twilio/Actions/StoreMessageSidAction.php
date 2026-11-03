<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Enums\MessageStatusEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Social\Messages\Actions\LinkMessageAttemptAction;
use Kanvas\Social\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Social\Messages\Models\Message;

/**
 * Every lead send hands its result here once the message is written, whatever the channel. Only an
 * SMS result is Twilio's to record; any other channel only links the attempt its provider filed at
 * send time (a Mailgun email) to the message.
 */
class StoreMessageSidAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(array $providerResponse): ?string
    {
        $channel = $providerResponse['channel'] ?? LeadCommunicationChannelEnum::SMS->value;
        if ($channel !== LeadCommunicationChannelEnum::SMS->value) {
            $this->linkProviderAttempt($providerResponse);

            return null;
        }

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
            $this->recordDelivery(
                MessageStatusEnum::FAILED,
                errorCode: (string) $errorCode,
                errorMessage: is_string($errorMessage) ? Str::trimToNull($errorMessage) : null,
            );
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
            $deliveryStatus = MessageStatusEnum::fromTwilio($status);
            if ($deliveryStatus !== null) {
                $this->recordDelivery($deliveryStatus, messageSid: $sid);
            }
        }

        return $sid;
    }

    private function linkProviderAttempt(array $providerResponse): void
    {
        if (! $this->message->exists) {
            return;
        }

        new LinkMessageAttemptAction($this->message)->execute($providerResponse);
    }

    private function recordDelivery(
        MessageStatusEnum $status,
        ?string $messageSid = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        if (! $this->message->exists) {
            return;
        }

        new RecordDeliveryOnMessageAction($this->message)->execute(
            $status,
            $messageSid,
            $errorCode,
            $errorMessage,
        );
    }
}
