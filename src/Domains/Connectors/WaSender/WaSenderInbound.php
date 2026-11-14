<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Baka\Support\Str;
use Illuminate\Http\Request;
use Kanvas\Communication\Contracts\Concerns\HasInboundDefaults;
use Kanvas\Communication\Contracts\HandlesProviderEvents;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\WaSender\Actions\ProcessWaSenderEventAction;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use LogicException;
use Override;

/**
 * WaSender's side of the communication webhook. Its conversations are not the one-to-one,
 * person-and-lead shape the shared pipeline files: a group room has many speakers and no lead, an
 * assistant chat opens no lead, and a lead chat takes its lead type, pipeline and album handling
 * from the receiver. So every event is filed by this connector's own actions, and the shared
 * pipeline is given nothing. What the domain still owns is the door (one webhook, the signature
 * checked first), the connection the number is, the owning agent's answer to a lead, and the
 * ledger our own sends are tracked on.
 */
class WaSenderInbound implements InboundSource, HandlesProviderEvents
{
    use HasInboundDefaults;

    private const array STATUS_EVENTS = [
        WebhookEventEnum::MESSAGE_SENT->value,
        WebhookEventEnum::MESSAGES_UPDATE->value,
        WebhookEventEnum::MESSAGE_RECEIPT_UPDATE->value,
    ];

    /**
     * WaSender sends the session's webhook secret back verbatim in `x-webhook-signature`. A
     * receiver that holds a secret takes nothing without it; one that never got a secret (a session
     * wired by hand) only takes unsigned calls, as it always has.
     */
    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = Str::trimToNull((string) ($receiver->configuration['webhook_secret'] ?? ''));
        $signature = (string) $request->header('x-webhook-signature', '');

        if ($secret === null) {
            return $signature === '';
        }

        return hash_equals($secret, $signature);
    }

    #[Override]
    public function handleProviderEvent(array $payload, ReceiverWebhook $receiver): ?array
    {
        if (! isset($payload['event'])) {
            return null;
        }

        $result = new ProcessWaSenderEventAction($receiver, $payload)->execute();
        $statuses = $this->recordStatuses($payload, $receiver);

        return $statuses !== null ? [...$result, 'deliveries' => $statuses] : $result;
    }

    #[Override]
    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        return [];
    }

    /**
     * Only a send that went through the ledger has a delivery to move; a status for anything else
     * (a reply in a group, a message typed on the phone) is counted and left alone.
     */
    #[Override]
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        if (! in_array($payload['event'] ?? null, self::STATUS_EVENTS, true)) {
            return null;
        }

        $data = (array) ($payload['data'] ?? []);
        $summary = ['recorded' => 0, 'duplicates' => 0, 'unmatched' => 0];

        foreach (array_is_list($data) ? $data : [$data] as $event) {
            $event = (array) $event;
            $providerStatus = $event['update']['status'] ?? $event['receipt']['status'] ?? $event['status'] ?? null;
            $status = StatusMap::toDeliveryStatus($providerStatus);
            $delivery = $status !== null ? self::deliveryOf($receiver, $event) : null;

            if ($delivery === null) {
                $summary['unmatched']++;

                continue;
            }

            $recorded = new RecordDeliveryEventAction(
                delivery: $delivery,
                status: $status,
                providerStatus: strtolower((string) $providerStatus),
                payload: $event
            )->execute();

            $summary[$recorded ? 'recorded' : 'duplicates']++;
        }

        return $summary;
    }

    /**
     * The ledger send a WaSender event is about, matched on either id it uses for a message: its
     * own (`msgId`) or WhatsApp's (`key.id`).
     *
     * @param array<string, mixed> $event
     */
    public static function deliveryOf(ReceiverWebhook $receiver, array $event): ?Delivery
    {
        $ids = array_values(array_filter([
            isset($event['msgId']) ? (string) $event['msgId'] : null,
            isset($event['key']['id']) ? (string) $event['key']['id'] : null,
        ]));

        if ($ids === []) {
            return null;
        }

        return Delivery::query()
            ->fromApp($receiver->app)
            ->fromCompany((int) $receiver->companies_id)
            ->reportedBy(WaSenderHandler::slug(), $ids)
            ->first();
    }

    /**
     * Never reached: media is downloaded by the action that files the message (the group and
     * assistant paths chain a burst before the download, the lead path after consent).
     */
    #[Override]
    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        throw new LogicException('WaSender media is downloaded by the connector, not the shared pipeline.');
    }
}
