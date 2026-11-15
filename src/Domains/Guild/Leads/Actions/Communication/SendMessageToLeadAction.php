<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions\Communication;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Attachment;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum as RespondIOConfigurationEnum;
use Kanvas\Connectors\RespondIO\Handlers\RespondIOHandler;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Exceptions\ModelNotFoundException as TemplateNotFoundException;
use Kanvas\Filesystem\Actions\ProcessVideoWithGifAction;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Customers\Actions\Communication\SendEmailToPeopleAction;
use Kanvas\Guild\Customers\Actions\Communication\SendMessageToPeopleAction;
use Kanvas\Guild\Customers\Services\ConsentGuard;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Exceptions\LeadOptedOutException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Users\Models\Users;
use Ramsey\Uuid\Uuid;
use Throwable;

class SendMessageToLeadAction
{
    /**
     * Hard upper bound on total media attached to one outbound SMS, regardless of batching.
     * Guards against runaway "entire camera roll" sends. Override per-app via
     * `twilio-mms-max-total-media`.
     */
    private const int DEFAULT_MMS_MAX_TOTAL_MEDIA = 30;

    protected array $processedFiles = [];
    protected array $videoEngagements = [];
    protected string $attemptUuid;
    protected ?string $attemptedFrom = null;
    protected ?string $attemptedTo = null;
    protected ?string $attemptedProvider = null;
    protected bool $isOptOutConfirmation = false;
    protected ?Users $signatureOwner = null;
    protected ?CampaignRecipient $campaignRecipient = null;
    protected string $emailTemplate = EmailComposer::DEFAULT_TEMPLATE;
    protected ?Agent $speakingAgent = null;
    protected ?Connection $conversationConnection = null;
    private ?SendMessageToPeopleAction $person = null;

    /**
     * For a text or WhatsApp written inside a conversation: it leaves on the number the customer
     * is already talking to, which wins over the company's default and the number a caller named.
     */
    public function inConversationOn(?Connection $connection): self
    {
        $this->conversationConnection = $connection;

        return $this;
    }

    public function withSignatureOwner(?Users $user): self
    {
        $this->signatureOwner = $user;

        return $this;
    }

    public function withEmailTemplate(string $template): self
    {
        $this->emailTemplate = $template;

        return $this;
    }

    public function forCampaignRecipient(?CampaignRecipient $recipient): self
    {
        $this->campaignRecipient = $recipient;

        return $this;
    }

    public function __construct(protected Lead $lead)
    {
        $this->attemptUuid = Uuid::uuid4()->toString();
    }

    /**
     * Sends through the do-not-contact guard. The ONLY legitimate caller is the single
     * post-revocation acknowledgement the FCC permits — see SendOptOutConfirmationAction.
     * Anything else reaching for this is a compliance bug.
     */
    public function allowOptOutConfirmation(): self
    {
        $this->isOptOutConfirmation = true;

        return $this;
    }

    public function execute(
        string $channel,
        string $message,
        ?string $from = '',
        ?string $title = null,
        bool $signature = true,
        ?Collection $files = null,
        ?string $to = null,
        ?array $cc = null,
        ?Agent $fromAgent = null
    ): array {
        $this->speakingAgent = $fromAgent;

        try {
            $this->guardDoNotContact();

            if ($files !== null && $files->isNotEmpty()) {
                $this->processedFiles = $this->prepareFiles($files);
                $this->createVideoEngagements();
            }

            return match ($channel) {
                LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message, $to),
                LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage(
                    (string) ($fromAgent?->smsPhoneNumber() ?? $from),
                    $message,
                    $to,
                ),
                LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage(
                    $message,
                    $title,
                    // Internal assistants act for the owner, not as the customer-facing persona.
                    $signature && ! ($fromAgent?->conversesWithUser() ?? false),
                    $to,
                    $cc,
                ),
                LeadCommunicationChannelEnum::VOICE->value => $this->sendVoiceMessage($message),
                default => throw new InvalidArgumentException('Unsupported communication channel ' . $channel),
            };
        } catch (Throwable $exception) {
            if ($this->classify($exception)['retryable']) {
                throw $exception;
            }

            return $this->failedResult($channel, $exception);
        }
    }

    protected function failedResult(string $channel, Throwable $exception): array
    {
        if (! $this->isExpectedLeadCondition($exception)) {
            report($exception);
        }

        return array_merge([
            'status' => 'error',
            'success' => false,
            'channel' => $channel,
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [],
            'error' => $exception->getMessage(),
            'attempt_uuid' => $this->attemptUuid,
            'from' => $this->attemptedFrom,
            'to' => $this->attemptedTo,
            'provider' => $this->attemptedProvider,
        ], $this->classify($exception));
    }

    /**
     * Only what this action raises itself is a verdict. A carrier's refusal never reaches here: it
     * comes back as a failed delivery, classified by its transport.
     *
     * @return array{classification: string, retryable: bool}
     */
    private function classify(Throwable $exception): array
    {
        $classification = match (true) {
            $exception instanceof LeadOptedOutException => 'opted_out',
            $exception instanceof LeadMissingContactException,
            $exception instanceof InvalidArgumentException => 'validation_failed',
            default => 'send_failed',
        };

        return ['classification' => $classification, 'retryable' => $classification === 'send_failed'];
    }

    protected function isExpectedLeadCondition(Throwable $exception): bool
    {
        return $exception instanceof LeadOptedOutException
            || $exception instanceof LeadMissingContactException;
    }

    protected function prepareFiles(Collection $files): array
    {
        $processed = [];

        foreach ($files as $file) {
            $fileType = $file->file_type ?? '';
            $mediaType = MediaTypeEnum::fromExtension($fileType);

            $fileData = [
                'url' => $file->url,
                'name' => $file->name,
                'type' => $mediaType,
                'file_type' => $fileType,
                'filesystem' => null,
            ];

            if ($mediaType->isVideo()) {
                $processedVideo = new ProcessVideoWithGifAction(
                    $this->lead->app,
                    $this->lead->company,
                    $this->lead->user,
                    $file->url
                )->execute();

                if ($processedVideo !== null) {
                    $processed[] = [
                        'is_processed_video' => true,
                        'video' => $processedVideo['video'],
                        'gif' => $processedVideo['gif'],
                    ];
                } else {
                    $processed[] = $fileData;
                }
            } else {
                $processed[] = $fileData;
            }
        }

        return $processed;
    }

    protected function createVideoEngagements(): void
    {
        foreach ($this->processedFiles as $index => $file) {
            if (! isset($file['is_processed_video']) || ! $file['is_processed_video']) {
                continue;
            }

            // Without a link the customer has no way to reach the video, so it goes out as a plain file.
            $this->processedFiles[$index] = $file['video'];

            $filesystems = [];
            if (isset($file['video']['filesystem'])) {
                $filesystems[] = $file['video']['filesystem'];
            }
            if (isset($file['gif']['filesystem'])) {
                $filesystems[] = $file['gif']['filesystem'];
            }

            if (empty($filesystems)) {
                continue;
            }

            try {
                $engagementData = new EngagementData(
                    app: $this->lead->app,
                    company: $this->lead->company,
                    user: $this->lead->user,
                    lead: $this->lead,
                    action: 'video',
                    requestId: Uuid::uuid4()->toString(),
                    source: 'video',
                    status: ActionStatusEnum::SENT,
                    files: $filesystems,
                );

                $engagement = new CreateEngagementAction($engagementData, true)->execute();
                $engagementUrl = $engagement->message->message['action_link'] ?? '';

                if (! empty($engagementUrl)) {
                    $this->processedFiles[$index] = $file;
                    $this->videoEngagements[] = [
                        'engagement' => $engagement,
                        'url' => $engagementUrl,
                    ];
                }
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    public function getVideoEngagements(): array
    {
        return $this->videoEngagements;
    }

    /**
     * Through whichever WhatsApp connection the company has and onto the delivery ledger: the
     * speaking agent's own number, else the default. Which vendor carries it is the connection's
     * business, with one exception the company states itself.
     */
    protected function sendWhatsAppMessage(string $message, ?string $to = null): array
    {
        $cellphone = $this->cellphoneFor($to, LeadCommunicationChannelEnum::WHATSAPP);
        $cellphone = $this->person()->phoneNumber($cellphone, ChannelTypeEnum::WHATSAPP);
        $this->attemptedTo = $cellphone;

        // A company on Respond.io that also has a WhatsApp number of its own must never answer a
        // Respond.io contact from that number, so its workspace is named.
        $viaRespondIo = $this->isRespondIoEnabled();
        $label = $viaRespondIo ? 'respondio' : 'whatsapp';

        $attachments = [];
        foreach ($this->processedFiles as $file) {
            if (! empty($file['is_processed_video']) || ! ($file['type'] ?? null) instanceof MediaTypeEnum) {
                continue;
            }

            $attachments[] = new Attachment($file['url'], $file['name'] ?? null);
        }

        $fromAccount = $this->conversationConnection?->account_key
            ?? ($viaRespondIo ? RespondIOHandler::accountKey($this->lead->app, $this->lead->company) : null);

        $delivery = $this->deliver(
            ChannelTypeEnum::WHATSAPP,
            $cellphone,
            Content::text($this->withEngagementLinks($message))->withAttachments($attachments),
            $fromAccount
        );

        $this->attemptedProvider = $delivery?->provider;
        $this->attemptedFrom = $delivery?->connection?->account_key;

        if ($delivery?->wasAccepted() !== true) {
            return $this->failedDelivery(
                $delivery,
                $cellphone,
                $label,
                'The company has no WhatsApp connection to send from'
            );
        }

        return [
            'channel' => $label,
            'to' => $cellphone,
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [['messageId' => $delivery->external_id]],
            'provider' => $this->attemptedProvider,
            'provider_message_id' => $delivery->external_id,
            'operation_id' => $delivery->request_id,
            'channel_slug' => $delivery->conversation()->channelSlug,
        ];
    }

    protected function isRespondIoEnabled(): bool
    {
        return (bool) $this->lead->company->get(RespondIOConfigurationEnum::ENABLED->value);
    }

    /**
     * The number the caller named, else the one the person send picks for the lead's person. The
     * per-address opt-out is checked here so no phone channel can skip it.
     */
    private function cellphoneFor(?string $to, LeadCommunicationChannelEnum $channel): string
    {
        $cellphone = $this->lead->people !== null
            ? $this->person()->addressFor(ChannelTypeEnum::from($channel->value), $to)
            : null;

        if ($cellphone === null) {
            throw new LeadMissingContactException('Lead does not have a cellphone number');
        }

        $this->guardDestinationOptOut($cellphone, $channel->value);

        return $cellphone;
    }

    /**
     * @return list<string>
     */
    private function engagementLinks(): array
    {
        return array_values(array_filter(array_column($this->videoEngagements, 'url')));
    }

    private function withEngagementLinks(string $message): string
    {
        $links = $this->engagementLinks();

        return $links === [] ? $message : trim($message . "\n\n" . implode("\n", $links));
    }

    /**
     * A text leaves through the company's connection for the number and is recorded on the delivery
     * ledger. Which line types may be texted, the length limit and the sender registration are the
     * provider's checks, made when it sends.
     */
    protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
    {
        $cellphone = $this->cellphoneFor($to, LeadCommunicationChannelEnum::SMS);
        $cellphone = $this->person()->phoneNumber($cellphone, ChannelTypeEnum::SMS);
        $this->attemptedFrom = $from !== '' ? Str::toE164($from) : null;
        $this->attemptedTo = $cellphone;

        return $this->sendSmsThroughCommunication(
            $cellphone,
            $this->withEngagementLinks($message),
            $this->getMediaUrlsForSms()
        );
    }

    /**
     * What is about the person and not the lead (their address, an address they stopped, the test
     * number, how many files a text carries) is the person send's to say.
     */
    private function person(): SendMessageToPeopleAction
    {
        return $this->person ??= new SendMessageToPeopleAction($this->lead->people);
    }

    /**
     * Every channel leaves through the one send a person is written to with. The acknowledgement of
     * a stop request is the only message that passes the consent check, and it speaks for nobody.
     */
    private function deliver(
        ChannelTypeEnum $channel,
        string $to,
        Content|string $content,
        ?string $fromAccount = null
    ): ?Delivery {
        $send = $this->person();

        return $this->isOptOutConfirmation
            ? $send->deliverOptOutConfirmation(
                $channel,
                $to,
                $content,
                $fromAccount
            )
            : $send->deliver(
                $channel,
                $to,
                $content,
                Sender::speaking($this->speakingAgent, $this->signatureOwner),
                $fromAccount
            );
    }

    /**
     * The conversation's own number first; then the number the caller named when the company has
     * it as a connection; otherwise the one the speaking agent owns, else the company's default. A
     * stale number in a rule's params must not stop the company from texting.
     */
    protected function smsConnection(Communication $communication, ?Sender $sender): ?Connection
    {
        if ($this->conversationConnection !== null) {
            return $this->conversationConnection;
        }

        $named = $this->attemptedFrom !== null
            ? $communication->connectionFor(ChannelTypeEnum::SMS, fromAccount: $this->attemptedFrom)
            : null;

        return $named ?? $communication->connectionFor(ChannelTypeEnum::SMS, $sender);
    }

    /**
     * The text rides the first batch, the rest carry media only. The caller files the message and
     * links the first delivery to it (LinkDeliveryMessageAction), so nothing is filed here.
     *
     * @param list<string> $mediaUrls
     */
    protected function sendSmsThroughCommunication(string $cellphone, string $fullMessage, array $mediaUrls): array
    {
        $communication = Communication::for($this->lead->app, $this->lead->company);
        $sender = Sender::speaking($this->speakingAgent, $this->signatureOwner);
        $connection = $this->smsConnection($communication, $this->speakingAgent !== null ? $sender : null);

        if ($connection === null) {
            return $this->failedSms(
                null,
                $cellphone,
                $fullMessage,
                'The company has no number connected to send texts from'
            );
        }

        $this->attemptedFrom = $connection->account_key;
        $this->attemptedProvider = $connection->provider;

        if ($this->isOptOutConfirmation) {
            $delivery = $this->deliver(
                ChannelTypeEnum::SMS,
                $cellphone,
                $fullMessage,
                $connection->account_key
            );

            return $delivery?->wasAccepted() === true
                ? $this->acceptedSms([$delivery], [[]], 0)
                : $this->failedSms($delivery, $cellphone, $fullMessage);
        }

        $batches = $this->person()->mediaBatches($mediaUrls);
        $deliveries = $this->person()->deliverText(
            $cellphone,
            $fullMessage,
            $batches,
            $sender,
            $connection->account_key
        );
        $last = $deliveries[array_key_last($deliveries)];

        if ($last?->wasAccepted() !== true) {
            return $this->failedSms($last, $cellphone, $fullMessage);
        }

        return $this->acceptedSms(
            $deliveries,
            $batches,
            $mediaUrls === [] ? 0 : $this->person()->mediaBatchSize()
        );
    }

    /**
     * @param list<Delivery> $accepted
     * @param list<list<string>> $batches
     */
    protected function acceptedSms(array $accepted, array $batches, int $batchSize): array
    {
        return [
            'channel' => 'sms',
            'batches' => count($batches),
            'batch_size' => $batchSize,
            'media_per_batch' => array_map('count', $batches),
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => array_map(
                fn (Delivery $delivery): array => [
                    'sid' => $delivery->external_id,
                    'status' => $delivery->status->value,
                    'from' => $this->attemptedFrom,
                    'to' => $delivery->to_address,
                ],
                $accepted
            ),
            'provider' => $this->attemptedProvider,
            'provider_message_id' => $accepted[0]->external_id,
            'operation_id' => $accepted[0]->request_id,
            'channel_slug' => $accepted[0]->conversation()->channelSlug,
        ];
    }

    /**
     * The ledger already recorded why; this is the same answer in the shape callers of this action
     * read. A recipient who unsubscribed with the carrier was opted out by the ledger and still
     * gets the note on the lead.
     */
    protected function failedSms(
        ?Delivery $delivery,
        string $cellphone,
        string $body,
        ?string $error = null
    ): array {
        $optedOut = $delivery?->recipientOptedOut() === true;

        if ($optedOut) {
            $this->recordOptOutNote($cellphone, $body);
        }

        $errorCode = (string) $delivery?->error_code;

        return [
            'status' => $optedOut ? 'failed' : 'error',
            'success' => false,
            'channel' => 'sms',
            'batches' => 0,
            'batch_size' => 0,
            'media_per_batch' => [],
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [],
            'opted_out' => $optedOut,
            'classification' => $optedOut ? 'opted_out' : 'send_failed',
            // The ledger never resends on its own; a person or the caller decides.
            'retryable' => false,
            'provider_error_code' => $errorCode !== '' ? $errorCode : null,
            'error' => $error ?? $delivery?->error ?? $errorCode,
            'from' => $this->attemptedFrom,
            'to' => $cellphone,
            'provider' => $this->attemptedProvider,
            'attempt_uuid' => $delivery?->uuid,
            'operation_id' => $delivery?->request_id,
        ];
    }

    protected function recordOptOutNote(string $cellphone, string $body): void
    {
        new RecordRecipientOptedOutNoteAction($this->lead)->execute($cellphone, $body);
    }

    /**
     * Channel-agnostic and checked once per send, ahead of the channel dispatch: a stop request is
     * person-wide, so it has to outrank whichever channel this particular call happens to use.
     */
    protected function guardDoNotContact(): void
    {
        if ($this->isOptOutConfirmation) {
            return;
        }

        if (ConsentGuard::isDoNotContact($this->lead->people, $this->lead)) {
            throw new LeadOptedOutException(
                'Lead is flagged do-not-contact; no further automated messages may be sent',
            );
        }
    }

    /**
     * Per-address opt-out; guardDoNotContact covers the person-wide case.
     */
    protected function guardDestinationOptOut(string $destination, string $channel): void
    {
        if ($this->isOptOutConfirmation) {
            return;
        }

        if ($this->person()->hasOptedOut($destination, ChannelTypeEnum::from($channel))) {
            throw new LeadOptedOutException(
                sprintf('Destination has opted out of %s communications', $channel),
            );
        }
    }

    protected function getMediaUrlsForSms(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $mediaUrls = [];
        $maxUrls = (int) ($this->lead->app->get(ConfigurationEnum::SMS_MEDIA_MAX_TOTAL->value) ?: self::DEFAULT_MMS_MAX_TOTAL_MEDIA);
        $maxUrls = max(1, $maxUrls);

        foreach ($this->processedFiles as $file) {
            if (count($mediaUrls) >= $maxUrls) {
                break;
            }

            // A video goes out as its engagement link only: the GIF as MMS media can fail the whole
            // message and take the link with it. The preview is the link page's job.
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                continue;
            }

            $type = $file['type'] ?? null;
            if (
                $type === MediaTypeEnum::IMAGE
                || $type === MediaTypeEnum::AUDIO
                || $type === MediaTypeEnum::DOCUMENT
            ) {
                $mediaUrls[] = $file['url'];
            }
        }

        return $mediaUrls;
    }

    protected function sendEmailMessage(
        string $message,
        ?string $title = null,
        bool $signature = true,
        ?string $to = null,
        ?array $cc = null
    ): array {
        $attachments = [];
        $message = $this->withEngagementLinks($message);

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                continue;
            }
            if (isset($file['url'])) {
                $attachments[] = $file['url'];
            }
        }

        $ccList = array_values(array_filter($cc ?? []));
        $subject = $title ?? 'Message from ' . $this->lead->company->name;
        $leadEmail = $this->lead->people !== null
            ? $this->person()->addressFor(ChannelTypeEnum::EMAIL, $to)
            : null;

        if (! $leadEmail) {
            throw new LeadMissingContactException('Lead does not have an email address');
        }

        $this->guardDestinationOptOut((string) $leadEmail, LeadCommunicationChannelEnum::EMAIL->value);

        $content = new SendEmailToPeopleAction($this->lead->people)->compose(
            message: $message,
            subject: $subject,
            attachments: $attachments,
            template: $this->resolveEmailTemplate(),
            options: new EmailOptions(
                cc: $ccList,
                companySignature: $signature,
                leadId: $this->lead->getId(),
                campaignRecipientId: $this->campaignRecipient?->getId(),
                trackOpens: true
            ),
            fromAgent: $this->speakingAgent,
            signatureOwner: $this->signatureOwner
        );
        $template = $content->email->template;

        $delivery = $this->deliver(ChannelTypeEnum::EMAIL, $leadEmail, $content);

        $this->attemptedProvider = $delivery?->provider;
        $this->attemptedFrom = $delivery?->connection?->account_key;

        if ($delivery?->wasAccepted() !== true) {
            return $this->failedEmail($delivery, $leadEmail);
        }

        return $this->emailResult(
            to: $leadEmail,
            ccList: $ccList,
            body: $message,
            attachments: $attachments,
            engagementUrls: $this->engagementLinks(),
            template: $template ?? 'agent-mailbox',
            signature: $content->email->companySignature,
            // No template means the mail left from the agent's own mailbox.
            from: $template === null ? $this->attemptedFrom : null,
        ) + [
            'provider' => $this->attemptedProvider,
            'provider_message_id' => $delivery->external_id,
            'attempt_uuid' => $delivery->uuid,
            'channel_slug' => $delivery->conversation()->channelSlug,
            'operation_id' => $delivery->request_id,
        ];
    }

    /**
     * The ledger already recorded why; this is the same answer in the shape callers of this action
     * read.
     */
    protected function failedEmail(?Delivery $delivery, string $to): array
    {
        return $this->failedDelivery(
            $delivery,
            $to,
            'email',
            'The company has no email address to send from'
        );
    }

    private function failedDelivery(
        ?Delivery $delivery,
        string $to,
        string $channel,
        string $fallbackError
    ): array {
        $errorCode = (string) $delivery?->error_code;

        return [
            'status' => 'error',
            'success' => false,
            'channel' => $channel,
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [],
            'classification' => 'send_failed',
            // The ledger never resends on its own; a person or the caller decides.
            'retryable' => false,
            'provider_error_code' => $errorCode !== '' ? $errorCode : null,
            'error' => $delivery?->error ?? ($errorCode !== '' ? $errorCode : $fallbackError),
            'from' => $this->attemptedFrom,
            'to' => $to,
            'provider' => $this->attemptedProvider,
            'attempt_uuid' => $delivery?->uuid,
            'operation_id' => $delivery?->request_id,
        ];
    }

    /**
     * Resolved before the send so the result names the template that was used: a company that
     * never got the requested one sends the default.
     */
    private function resolveEmailTemplate(): string
    {
        if ($this->emailTemplate === EmailComposer::DEFAULT_TEMPLATE) {
            return EmailComposer::DEFAULT_TEMPLATE;
        }

        try {
            TemplatesRepository::getByName($this->emailTemplate, $this->lead->app, $this->lead->company);
        } catch (TemplateNotFoundException) {
            return EmailComposer::DEFAULT_TEMPLATE;
        }

        return $this->emailTemplate;
    }

    /**
     * `from` is only set when the mail leaves from an agent's own mailbox; otherwise the address
     * is the company's, which the connection decides.
     *
     * @param array<int, string> $ccList
     * @param array<int, string> $attachments
     * @param array<int, string> $engagementUrls
     *
     * @return array<string, mixed>
     */
    private function emailResult(
        string $to,
        array $ccList,
        string $body,
        array $attachments,
        array $engagementUrls,
        string $template,
        bool $signature,
        ?string $from = null
    ): array {
        return [
            'channel' => 'email',
            'to' => $to,
            'cc' => $ccList,
            'template' => $template,
            'from' => $from,
            'body_length' => strlen($body),
            'signature' => $signature,
            'engagement_urls' => $engagementUrls,
            'attachments' => $attachments,
            'attachments_count' => count($attachments),
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
        ];
    }

    protected function sendVoiceMessage(string $instructions = ''): array
    {
        $agent = Agent::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->where('name', AgentEnum::VOICE_OUTREACH->value)
            ->firstOrFail();

        $sessionResult = InitVoiceSessionAction::fromLead(
            $this->lead,
            $agent,
            $instructions ?: null
        )->execute();
        $callResult = TriggerVoiceCallAction::fromLead($this->lead)->execute();

        return array_merge($sessionResult, $callResult);
    }
}
