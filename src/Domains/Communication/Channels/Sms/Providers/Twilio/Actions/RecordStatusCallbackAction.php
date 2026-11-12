<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\StatusMap;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * A Twilio delivery receipt on the communication webhook. Only a send that went through the ledger
 * reports here: its `statusCallback` names this receiver. The attempt tables and the legacy status
 * receiver belong to the sends that have not moved onto deliveries yet.
 */
final class RecordStatusCallbackAction
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly ReceiverWebhook $receiver,
        private readonly array $payload
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $sid = trim((string) ($this->payload['MessageSid'] ?? $this->payload['SmsSid'] ?? ''));
        $status = trim((string) ($this->payload['MessageStatus'] ?? $this->payload['SmsStatus'] ?? ''));
        $errorCode = Str::trimToNull($this->payload['ErrorCode'] ?? null);

        if ($sid === '' || $status === '') {
            return [
                'status' => 422,
                'message' => 'MessageSid and MessageStatus are required',
            ];
        }

        $delivery = Delivery::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($this->receiver->company)
            ->where('provider', TwilioHandlers::slug())
            ->where('external_id', $sid)
            ->first();

        if (! $delivery instanceof Delivery) {
            return ['message' => 'No outbound text on record for this MessageSid, ignored', 'sid' => $sid];
        }

        $deliveryStatus = StatusMap::toDeliveryStatus($status);
        $statusBefore = $delivery->status;

        $recorded = $deliveryStatus !== null && new RecordDeliveryEventAction(
            delivery: $delivery,
            status: $deliveryStatus,
            providerStatus: strtolower($status),
            payload: $this->payload,
            errorCode: $errorCode,
            error: Str::trimToNull($this->payload['ErrorMessage'] ?? null)
        )->execute();

        if ($recorded) {
            if ($delivery->status !== $statusBefore) {
                new RecordDeliveryOnMessageAction($delivery)->execute();
            }

            new ApplyDestinationRemediationAction(
                $this->receiver->app,
                $this->receiver->company,
                (string) ($this->payload['To'] ?? $delivery->to_address ?? ''),
                (string) $errorCode
            )->execute();
        }

        return [
            'message' => $recorded ? 'Twilio status recorded on delivery' : 'Twilio status already recorded on delivery',
            'sid' => $sid,
            'twilio_status' => $status,
            'delivery_id' => $delivery->getId(),
        ];
    }
}
