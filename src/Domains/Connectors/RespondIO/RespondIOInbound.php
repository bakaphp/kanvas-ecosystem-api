<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Carbon\Carbon;
use finfo;
use Illuminate\Http\Request;
use Kanvas\Communication\Contracts\Concerns\HasInboundDefaults;
use Kanvas\Communication\Contracts\HandlesProviderEvents;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\RespondIO\Actions\ProcessCallEndedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessCommentAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactAssigneeUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactLifecycleUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactTagUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessConversationClosedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessConversationOpenedAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;
use Kanvas\Connectors\RespondIO\Enums\WebhookEventEnum;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Respond.io's side of the communication webhook. One payload is one event (`event_type`). A
 * message in or out goes through the shared pipeline; everything else Respond.io reports about a
 * contact or a conversation is its own kind of record and is filed by this connector.
 */
class RespondIOInbound implements InboundSource, HandlesProviderEvents
{
    use HasInboundDefaults;

    private const array MESSAGE_EVENTS = [
        WebhookEventEnum::MESSAGE_RECEIVED->value,
        WebhookEventEnum::MESSAGE_SENT->value,
    ];

    /**
     * `sender.source` of a message that left through the API, as opposed to `user` (typed in the
     * Respond.io inbox) or `contact`.
     */
    private const string SENT_THROUGH_API = 'api';

    /**
     * @var array<string, Delivery|null>
     */
    private array $ownDeliveries = [];

    /**
     * A workspace that stored its webhook's signing key has every request checked against it. One
     * that has not is reached only through its own receiver URL, as it always was.
     */
    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        $key = $receiver->company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value);

        if (! is_string($key) || $key === '') {
            return true;
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        return $signature !== '' && hash_equals(
            base64_encode(hash_hmac('sha256', $request->getContent(), $key, true)),
            $signature
        );
    }

    #[Override]
    public function handleProviderEvent(array $payload, ReceiverWebhook $receiver): ?array
    {
        $eventType = (string) ($payload['event_type'] ?? 'unknown');

        if (in_array($eventType, self::MESSAGE_EVENTS, true)) {
            // A contact with no phone cannot be filed as a person or answered by phone; filing its
            // Respond.io id as a number would poison every phone lookup in the company.
            return $this->contactPhone($payload) === null
                ? $this->handled($eventType, ['status' => 'skipped', 'reason' => 'contact_has_no_phone'])
                : null;
        }

        $action = match ($eventType) {
            WebhookEventEnum::COMMENT_CREATED->value => ProcessCommentAction::class,
            WebhookEventEnum::CONVERSATION_OPENED->value => ProcessConversationOpenedAction::class,
            WebhookEventEnum::CONVERSATION_CLOSED->value => ProcessConversationClosedAction::class,
            WebhookEventEnum::CONTACT_UPDATED->value => ProcessContactUpdatedAction::class,
            WebhookEventEnum::CONTACT_TAG_UPDATED->value => ProcessContactTagUpdatedAction::class,
            WebhookEventEnum::CONTACT_ASSIGNEE_UPDATED->value => ProcessContactAssigneeUpdatedAction::class,
            WebhookEventEnum::CONTACT_LIFECYCLE_UPDATED->value => ProcessContactLifecycleUpdatedAction::class,
            WebhookEventEnum::CALL_ENDED->value => ProcessCallEndedAction::class,
            default => null,
        };

        return $this->handled(
            $eventType,
            $action !== null
                ? new $action($receiver, $payload)->execute()
                : ['event_type' => $eventType, 'status' => 'acknowledged']
        );
    }

    #[Override]
    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $phone = $this->contactPhone($payload);

        if (! in_array($eventType, self::MESSAGE_EVENTS, true) || $phone === null) {
            return [];
        }

        $fromUs = $eventType === WebhookEventEnum::MESSAGE_SENT->value;

        // Our own send coming back: the ledger already has it, and the caller filed the message.
        // The delivery is known by its last part only, so the files sent ahead of the text are
        // told apart by how they left: nobody typed a message that went out through the API.
        if ($fromUs
            && (($payload['sender']['source'] ?? null) === self::SENT_THROUGH_API
                || $this->ownDelivery($payload, $receiver) !== null)
        ) {
            return [];
        }

        $messageData = (array) ($payload['message'] ?? []);
        $content = (array) ($messageData['message'] ?? []);
        $type = MessageTypeEnum::getMessageType($content);
        $messageId = (string) ($messageData['messageId'] ?? Str::uuid()->toString());
        $attachments = [];

        if (! $fromUs && $type->isDocumentType() && ! empty($content['attachment']['url'])) {
            $attachments[] = new InboundAttachment(
                (string) $content['attachment']['url'],
                (string) ($content['attachment']['mimeType'] ?? '')
            );
        }

        return [new InboundMessage(
            provider: RespondIOHandler::slug(),
            channel: ChannelTypeEnum::WHATSAPP,
            inboundIdentity: RespondIOHandler::accountKey($receiver->app, $receiver->company),
            from: $phone,
            providerMessageId: $messageId,
            conversationSlug: WhatsAppTransport::channelSlug($phone),
            messageVerb: $type->value,
            // The slug these messages have always had, so a redelivery finds the copy filed before.
            messageSlug: 'respondio-' . Str::slug($messageId . '-' . $phone),
            text: self::text($content, $type),
            fromUs: $fromUs,
            raw: $payload,
            fromName: $this->contactName($payload),
            attachments: $attachments,
            personCustomFields: ['respondio_phone' => $phone],
            personTags: ['respondio', 'ai-agent'],
        )];
    }

    /**
     * Respond.io reports a message we sent the way it reports one a person typed in its inbox:
     * `message.sent`, with the id the send call returned. That is the only status it gives us.
     */
    #[Override]
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        if (($payload['event_type'] ?? null) !== WebhookEventEnum::MESSAGE_SENT->value) {
            return null;
        }

        $delivery = $this->ownDelivery($payload, $receiver);

        if ($delivery === null) {
            return null;
        }

        $statuses = (array) ($payload['message']['status'] ?? []);
        $latest = (array) (end($statuses) ?: []);
        $providerStatus = strtolower((string) ($latest['value'] ?? 'sent'));

        $recorded = new RecordDeliveryEventAction(
            delivery: $delivery,
            status: self::deliveryStatus($providerStatus),
            providerStatus: $providerStatus,
            payload: (array) ($payload['message'] ?? []),
            occurredAt: isset($latest['timestamp']) ? Carbon::createFromTimestamp((int) $latest['timestamp']) : null,
        )->execute();

        return ['recorded' => (int) $recorded, 'duplicates' => (int) ! $recorded, 'unmatched' => 0];
    }

    /**
     * The file sits at a URL Respond.io hands out. Its type is read from the bytes: the payload
     * names one only sometimes.
     */
    #[Override]
    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        $contents = SafeUrlFetcher::fetch($attachment->url);

        return new StoreInboundFileAction(
            $message,
            $contents,
            (string) new finfo(FILEINFO_MIME_TYPE)->buffer($contents) ?: $attachment->contentType,
            'respondio'
        )->execute();
    }

    #[Override]
    public function burstPolicy(InboundMessage $inbound, Companies $company): BurstPolicy
    {
        return BurstPolicy::fromCompanySettings($company, 'respondio', ['respondio:' . Str::digitsOnly($inbound->from)]);
    }

    private static function text(array $content, MessageTypeEnum $type): ?string
    {
        return match ($type) {
            MessageTypeEnum::IMAGE,
            MessageTypeEnum::VIDEO => $content['caption'] ?? $content['attachment']['description'] ?? null,
            MessageTypeEnum::FILE,
            MessageTypeEnum::AUDIO => $content['attachment']['fileName'] ?? $content['caption'] ?? null,
            MessageTypeEnum::LOCATION => $content['address'] ?? null,
            MessageTypeEnum::QUICK_REPLY => $content['title'] ?? null,
            default => $content['text'] ?? null,
        };
    }

    private static function deliveryStatus(string $providerStatus): DeliveryStatusEnum
    {
        return match ($providerStatus) {
            'delivered' => DeliveryStatusEnum::DELIVERED,
            'read' => DeliveryStatusEnum::READ,
            'failed' => DeliveryStatusEnum::FAILED,
            // `pending` and `sent`: it left for the channel, which is all acceptance ever meant.
            default => DeliveryStatusEnum::ACCEPTED,
        };
    }

    /**
     * Asked twice for one webhook (is it ours to file, and what status does it report), so the
     * answer is kept for the life of the job this instance serves.
     */
    private function ownDelivery(array $payload, ReceiverWebhook $receiver): ?Delivery
    {
        $messageId = (string) ($payload['message']['messageId'] ?? '');

        if ($messageId === '') {
            return null;
        }

        $key = $receiver->getId() . ':' . $messageId;

        if (! array_key_exists($key, $this->ownDeliveries)) {
            $this->ownDeliveries[$key] = Delivery::query()
                ->fromApp($receiver->app)
                ->fromCompany((int) $receiver->companies_id)
                ->reportedBy(RespondIOHandler::slug(), $messageId)
                ->first();
        }

        return $this->ownDeliveries[$key];
    }

    private function contactPhone(array $payload): ?string
    {
        return Str::trimToNull((string) ($payload['contact']['phone'] ?? ''));
    }

    private function contactName(array $payload): ?string
    {
        return Str::trimToNull(trim(($payload['contact']['firstName'] ?? '') . ' ' . ($payload['contact']['lastName'] ?? '')));
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function handled(string $eventType, array $result): array
    {
        return [
            'message' => 'RespondIO webhook processed successfully',
            'event_type' => $eventType,
            'result' => $result,
        ];
    }
}
