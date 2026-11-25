<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Connectors\Twilio\StatusMap;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * A Twilio delivery receipt on the communication webhook: every text goes through the ledger, and
 * its `statusCallback` names this receiver.
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
            ->fromCompany((int) $this->receiver->companies_id)
            ->reportedBy(TwilioHandlers::slug(), $sid)
            ->first();

        if (! $delivery instanceof Delivery) {
            return ['message' => 'No outbound text on record for this MessageSid, ignored', 'sid' => $sid];
        }

        $deliveryStatus = StatusMap::toDeliveryStatus($status);

        $recorded = $deliveryStatus !== null && new RecordDeliveryEventAction(
            delivery: $delivery,
            status: $deliveryStatus,
            providerStatus: strtolower($status),
            payload: $this->payload,
            errorCode: $errorCode,
            error: Str::trimToNull($this->payload['ErrorMessage'] ?? null)
        )->execute();

        if ($recorded) {
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
