<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Http\Request;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\DownloadMessageFileAction;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\RecordStatusCallbackAction;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\BurstConfigEnum;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Handlers\TwilioHandlers;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Services\TwilioReceiverService;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Services\WebhookSignatureValidator;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\Actions\FlushInboundBurstAction;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Twilio's side of the communication webhook. One URL takes both kinds of request Twilio makes: an
 * inbound text always carries `Body` (empty for a picture sent alone), a delivery receipt never does.
 */
class TwilioInbound implements InboundSource
{
    /**
     * The URL Twilio is told to report an outbound message's fate to, or null when the tenant has no
     * receiver — in which case messages go out without one and are simply not tracked. A receiver
     * made by hand for the old status job still counts until setup gives the tenant its own.
     */
    public static function statusCallbackUrl(AppInterface $app, Companies $company): ?string
    {
        $receiver = new CommunicationReceivers()->find($app, $company, TwilioHandlers::slug());

        if ($receiver?->is_active) {
            return $receiver->getUrl();
        }

        return new TwilioReceiverService()->statusCallbackUrl($app, $company);
    }

    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        return WebhookSignatureValidator::validate(
            request: $request,
            company: $receiver->company,
            expectedUrl: $receiver->getUrl(),
        );
    }

    #[Override]
    public function messages(array $payload, ReceiverWebhook $receiver): array
    {
        return $this->isStatusCallback($payload) ? [] : [$this->parse($payload, $receiver->company)];
    }

    #[Override]
    public function recordStatuses(array $payload, ReceiverWebhook $receiver): ?array
    {
        return $this->isStatusCallback($payload)
            ? new RecordStatusCallbackAction($receiver, $payload)->execute()
            : null;
    }

    #[Override]
    public function downloadAttachment(Message $message, InboundAttachment $attachment): Filesystem
    {
        return new DownloadMessageFileAction($message, $attachment->url, $attachment->contentType)
            ->execute()['media'];
    }

    /**
     * One burst per sender per channel. SMS has no album id or thread id to key on, so the number
     * that sent it is the whole correlation.
     */
    #[Override]
    public function burstPolicy(InboundMessage $inbound, Companies $company): BurstPolicy
    {
        $idle = BurstConfigEnum::BURST_IDLE_SECONDS->getInt($company);

        return new BurstPolicy(
            correlationKeys: ['sms:' . Str::normalizePhoneNumber($inbound->from)],
            chainIdleSeconds: $idle,
            closeIdleSeconds: $idle,
            maxSeconds: BurstConfigEnum::BURST_MAX_SECONDS->getInt($company),
            jitterSeconds: BurstConfigEnum::BURST_JITTER_SECONDS->getInt($company),
        );
    }

    #[Override]
    public function burstHandler(): string
    {
        return FlushInboundBurstAction::class;
    }

    /**
     * Twilio answers HELP itself with the carrier's advisory.
     */
    #[Override]
    public function answersHelpItself(): bool
    {
        return true;
    }

    private function isStatusCallback(array $payload): bool
    {
        return ! array_key_exists('Body', $payload);
    }

    private function parse(array $request, Companies $company): InboundMessage
    {
        $hijacked = false;
        $overwrite = $company->get('overwrite_phone_number');

        // A test session can stand in for another number; the person already on file for it is kept.
        if ($company->get('allow_session_hijack', false)
            && is_array($overwrite)
            && isset($overwrite[$request['From']])
        ) {
            $request['From'] = $overwrite[$request['From']];
            $hijacked = true;
        }

        $from = (string) $request['From'];
        $sid = (string) ($request['SmsMessageSid'] ?? $request['MessageSid'] ?? '');
        $attachments = [];

        for ($i = 0; isset($request["MediaUrl{$i}"]); $i++) {
            $attachments[] = new InboundAttachment($request["MediaUrl{$i}"], (string) ($request["MediaContentType{$i}"] ?? ''));
        }

        return new InboundMessage(
            provider: TwilioHandlers::slug(),
            channel: ChannelTypeEnum::SMS,
            inboundIdentity: (string) $request['To'],
            from: $from,
            providerMessageId: $sid,
            conversationSlug: SmsTransport::channelSlug($from),
            messageVerb: SmsTransport::MESSAGE_VERB,
            messageSlug: 'twilio-' . Str::slug($sid . '-' . $from),
            text: $request['Body'] ?? null,
            fromUs: $from === (string) $request['To'],
            raw: $request,
            // Twilio's own classification wins when present — it reflects what the carrier did to the
            // number. Otherwise the shared matcher decides, as for every other inbound channel.
            consentSignal: ConsentSignalEnum::tryFrom(strtolower(trim((string) ($request['OptOutType'] ?? '')))),
            attachments: $attachments,
            personCustomFields: ['twilio_jid' => Str::normalizePhoneNumber($from)],
            personTags: ['sms', 'twilio'],
            reuseKnownPerson: $hijacked
        );
    }
}
