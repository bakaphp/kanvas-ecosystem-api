<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Messages\Models\Request;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Files an email sent through Mailgun in the communication ledger: one request, one email delivery,
 * keyed by its Message-Id, the only thing Mailgun's later events carry back to it.
 *
 * Only for a send made straight through Mailgun's API outside a connection (`SendAsAgentMailboxAction`,
 * the email an agent sends a teammate); everything else is filed by the ledger itself. A 200 is
 * Mailgun's own `accepted`; a refused send has no Message-Id and is filed `failed`.
 *
 * A teammate is a user, not a People row, so the request names no person. A Message written for the
 * mail afterwards is linked by LinkDeliveryMessageAction from the send result.
 */
final class FileEmailDeliveryAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly Sender $sender,
        private readonly string $to,
        private readonly Content $content,
    ) {
    }

    public function accepted(string $mailgunMessageId): Delivery
    {
        $externalId = self::normalizeMessageId($mailgunMessageId);

        $existing = Delivery::query()
            ->fromApp($this->app)
            ->reportedBy(IntegrationsEnum::MAILGUN->value, $externalId)
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
        return DB::connection('workflow')->transaction(function () use ($outcome): Delivery {
            $request = Request::create([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'kind' => OperationKindEnum::SEND,
                'content' => $this->content->toArray(),
                'sender' => $this->sender->toArray(),
                'channels' => [ChannelTypeEnum::EMAIL->value],
            ]);

            return Delivery::create([
                'apps_id' => $request->apps_id,
                'companies_id' => $request->companies_id,
                'request_id' => $request->getId(),
                'channel' => ChannelTypeEnum::EMAIL,
                'provider' => IntegrationsEnum::MAILGUN->value,
                'to_address' => ChannelTypeEnum::EMAIL->normalizeAddress($this->to),
                'last_event_at' => now(),
                ...$outcome,
            ]);
        });
    }
}
