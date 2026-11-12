<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
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
use Kanvas\Notifications\Notification;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Throwable;

/**
 * Files an email sent through Mailgun in the communication ledger: one request for the person, one email
 * delivery, keyed by its Message-Id, the only thing Mailgun's later events carry back to it.
 *
 * - Agent mailbox (send API): filed after the send. A 200 is Mailgun's own `accepted`; a refused send
 *   has no Message-Id, so it is filed `failed` under the sender's uuid, which the send result carries.
 * - Company SMTP (`Blank` notifications): the send is queued, so the id is fixed on the notification
 *   up front and the delivery filed `sending`; MarkSmtpEmailAcceptedListener moves it to `accepted`
 *   once the SMTP server takes it.
 *
 * The Message is often written after the send; it is linked here only when the caller already has it,
 * otherwise LinkDeliveryMessageAction links it from the send result.
 */
final class FileEmailDeliveryAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly Sender $sender,
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
            ->fromApp($this->app)
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
     * Only Mailgun's SMTP reports back on what it was handed; mail on any other server is never filed.
     */
    public static function sendsOnMailgunSmtp(AppInterface $app, ?CompanyInterface $company): bool
    {
        $host = new SmtpRuntimeConfiguration($app, $company)->loadSmtpSettings()['host'] ?? null;

        return is_string($host) && str_contains(strtolower($host), 'mailgun');
    }

    /**
     * Fixes the Message-ID on a notification about to go out on the company's Mailgun SMTP identity
     * (check sendsOnMailgunSmtp() first) and files it `sending`. A failure to file is reported, never
     * thrown: bookkeeping must not stop the email.
     */
    public function sending(Notification $notification): ?Delivery
    {
        $fromAddress = (string) new SmtpRuntimeConfiguration($this->app, $this->company)->getFromEmail()['address'];
        $fromDomain = Str::after($fromAddress, '@');
        $messageId = bin2hex(random_bytes(16)) . '@' . ($fromDomain !== '' && $fromDomain !== $fromAddress ? $fromDomain : 'kanvas.dev');
        $notification->setMessageId($messageId);

        try {
            return $this->file([
                'status' => DeliveryStatusEnum::SENDING,
                'external_id' => $messageId,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
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
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'kind' => OperationKindEnum::SEND,
                'people_id' => $this->people?->getId(),
                'content' => $this->content->toArray(),
                'sender' => $this->sender->toArray(),
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
