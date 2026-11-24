<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Http\Request;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Contracts\Concerns\HasInboundDefaults;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Messages\DataTransferObject\InboundAttachment;
use Kanvas\Communication\Messages\DataTransferObject\InboundMessage;
use Kanvas\Communication\Messages\DataTransferObject\InboundSenderPolicy;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\DownloadMessageFileAction;
use Kanvas\Connectors\Twilio\Actions\RecordStatusCallbackAction;
use Kanvas\Connectors\Twilio\Handlers\TwilioHandlers;
use Kanvas\Connectors\Twilio\Services\WebhookSignatureValidatorService;
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
    use HasInboundDefaults;

    /**
     * Has to exceed the gap between two texts a person sends back to back; under that, each one
     * opens its own turn and the agent answers twice.
     */
    private const int BURST_IDLE_SECONDS = 15;

    /**
     * Superseded by `twilio_burst_idle_seconds`, and read as its default so a tenant that tuned the
     * old knob keeps their tuning.
     */
    private const string LEGACY_BATCH_DELAY = 'twilio_batch_delay_seconds';

    /**
     * The URL Twilio is told to report an outbound message's fate to: the company's communication
     * receiver, which takes its texts and its delivery receipts alike. Null while the company has
     * none, in which case a text goes out without one and is simply not tracked.
     */
    public static function statusCallbackUrl(AppInterface $app, Companies $company): ?string
    {
        $receiver = new CommunicationReceivers()->find($app, $company, TwilioHandlers::slug());

        return $receiver?->is_active ? $receiver->getUrl() : null;
    }

    #[Override]
    public function authenticate(Request $request, ReceiverWebhook $receiver): bool
    {
        return WebhookSignatureValidatorService::validate(
            request: $request,
            company: $receiver->company,
            app: $receiver->app,
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
        return BurstPolicy::fromCompanySettings(
            company: $company,
            prefix: 'twilio',
            correlationKeys: ['sms:' . Str::normalizePhoneNumber($inbound->from)],
            idleSeconds: (int) ($company->get(self::LEGACY_BATCH_DELAY) ?? self::BURST_IDLE_SECONDS),
            maxSeconds: 90,
            jitterSeconds: 0,
        );
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
        $tester = null;
        $overwrite = $company->get('overwrite_phone_number');

        // A test session can stand in for another number; the person already on file for it is kept.
        if ($company->get('allow_session_hijack', false)
            && is_array($overwrite)
            && isset($overwrite[$request['From']])
        ) {
            $tester = (string) $request['From'];
            $request['From'] = $overwrite[$tester];
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
            senderPolicy: InboundSenderPolicy::customers(keepsPersonAsFound: $tester !== null),
            replyTo: $tester
        );
    }
}
