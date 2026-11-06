<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Messages\Models\Request;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Files an agent-mailbox email in the communication ledger: one request for the person, one email
 * delivery. An accepted send is keyed by its Message-Id, the only thing Mailgun's later events carry
 * back to it, and starts `accepted`: a 200 from the send API is Mailgun's own `accepted`. A refused send
 * has no Message-Id, so it is filed `failed` under the sender's uuid, which the send result carries.
 *
 * The Message is often written after the send; it is linked here only when the caller already has it,
 * otherwise LinkDeliveryMessageAction links it from the send result.
 */
final class FileEmailDeliveryAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $to,
        private readonly Content $content,
        private readonly ?People $people = null,
        private readonly ?Message $message = null,
        private readonly ?CampaignRecipient $campaignRecipient = null,
        private readonly ?string $uuid = null,
    ) {
    }

    public function accepted(string $mailgunMessageId): Delivery
    {
        $externalId = self::normalizeMessageId($mailgunMessageId);

        $existing = Delivery::query()
            ->fromApp($this->agent->app)
            ->where('provider', IntegrationsEnum::MAILGUN->value)
            ->where('external_id', $externalId)
            ->first();

        return $existing ?? $this->file([
            'status' => DeliveryStatusEnum::ACCEPTED,
            'external_id' => $externalId,
            'accepted_at' => now(),
        ]);
    }

    public function refused(string $error): Delivery
    {
        return $this->file([
            'status' => DeliveryStatusEnum::FAILED,
            'error_code' => 'rejected_by_provider',
            'error' => $error,
            // Nothing was queued, so no event can ever arrive for it.
            'events_closed_at' => now(),
        ]);
    }

    /**
     * The send API answers `<20261103.1@mg.example.com>`; every event spells the same id without the
     * angle brackets.
     */
    public static function normalizeMessageId(string $messageId): string
    {
        return trim(trim($messageId), '<>');
    }

    private function file(array $outcome): Delivery
    {
        $delivery = DB::connection('workflow')->transaction(function () use ($outcome): Delivery {
            $request = Request::create([
                'apps_id' => $this->agent->apps_id,
                'companies_id' => $this->agent->companies_id,
                'kind' => OperationKindEnum::SEND,
                'people_id' => $this->people?->getId(),
                'content' => $this->content->toArray(),
                'sender' => Sender::agent($this->agent)->toArray(),
                'channels' => [ChannelTypeEnum::EMAIL->value],
                'filed_message_id' => $this->message?->getId(),
                'campaign_recipient_id' => $this->campaignRecipient?->getId(),
            ]);

            return Delivery::create(array_filter([
                'uuid' => $this->uuid,
                'apps_id' => $request->apps_id,
                'companies_id' => $request->companies_id,
                'request_id' => $request->getId(),
                'channel' => ChannelTypeEnum::EMAIL,
                'provider' => IntegrationsEnum::MAILGUN->value,
                'to_address' => ChannelTypeEnum::EMAIL->normalizeAddress($this->to),
                'message_id' => $this->message?->getId(),
                'last_event_at' => now(),
                ...$outcome,
            ], fn (mixed $value): bool => $value !== null));
        });

        new RecordDeliveryOnMessageAction($delivery)->execute();

        return $delivery;
    }
}
