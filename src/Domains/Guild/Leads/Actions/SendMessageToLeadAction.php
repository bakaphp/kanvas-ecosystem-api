<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\RespondIO\Client as RespondIOClient;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum as RespondIOConfigurationEnum;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum as WaSenderConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Actions\ProcessVideoWithGifAction;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;
use Ramsey\Uuid\Uuid;

class SendMessageToLeadAction
{
    /**
     * Default media items per Twilio MMS API call. Override per-app via
     * `twilio-mms-batch-size` setting. Twilio's documented hard cap is 10 but
     * 21623 fires on some account/carrier combos before that; 8 is the safe
     * default measured against the active production account.
     */
    private const int DEFAULT_MMS_BATCH_SIZE = 8;
    private const int MAX_MMS_BATCH_SIZE = 10;

    /**
     * Hard upper bound on total media attached to one outbound SMS, regardless of batching.
     * Guards against runaway "entire camera roll" sends. Override per-app via
     * `twilio-mms-max-total-media`.
     */
    private const int DEFAULT_MMS_MAX_TOTAL_MEDIA = 30;

    protected array $processedFiles = [];
    protected array $videoEngagements = [];

    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(
        string $channel,
        string $message,
        ?string $from = '',
        ?string $title = null,
        bool $signature = true,
        ?Collection $files = null,
        ?string $to = null,
        ?array $cc = null
    ): array {
        if ($files !== null && $files->isNotEmpty()) {
            $this->processedFiles = $this->prepareFiles($files);
            $this->createVideoEngagements();
        }

        return match ($channel) {
            LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message, $to),
            LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage((string) $from, $message, $to),
            LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage($message, $title, $signature, $to, $cc),
            LeadCommunicationChannelEnum::VOICE->value => $this->sendVoiceMessage($message),
            default => throw new InvalidArgumentException('Unsupported communication channel ' . $channel),
        };
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
        foreach ($this->processedFiles as $file) {
            if (! isset($file['is_processed_video']) || ! $file['is_processed_video']) {
                continue;
            }

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
                    $this->videoEngagements[] = [
                        'engagement' => $engagement,
                        'url' => $engagementUrl,
                        'gif_url' => $file['gif']['url'] ?? null,
                    ];
                }
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    public function getProcessedFilesystems(): array
    {
        $filesystems = [];

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                if (isset($file['video']['filesystem'])) {
                    $filesystems[] = $file['video']['filesystem'];
                }
                if (isset($file['gif']['filesystem'])) {
                    $filesystems[] = $file['gif']['filesystem'];
                }
            } elseif (isset($file['filesystem']) && $file['filesystem'] instanceof Filesystem) {
                $filesystems[] = $file['filesystem'];
            }
        }

        return $filesystems;
    }

    public function getProcessedFilesUrls(): array
    {
        $urls = [];

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                if (isset($file['video']['url'])) {
                    $urls[] = $file['video']['url'];
                }
                if (isset($file['gif']['url'])) {
                    $urls[] = $file['gif']['url'];
                }
            } elseif (isset($file['url'])) {
                $urls[] = $file['url'];
            }
        }

        return $urls;
    }

    public function getVideoEngagements(): array
    {
        return $this->videoEngagements;
    }

    protected function sendWhatsAppMessage(string $message, ?string $to = null): array
    {
        if ($this->isRespondIoEnabled()) {
            return $this->sendRespondIoMessage($message, $to);
        }

        $isFromWhatsapp = (bool) $this->lead->get(ConfigurationEnum::IS_FROM_WHATSAPP->value);
        $hasOutboundConfigured = ! empty($this->lead->app->get(WaSenderConfigurationEnum::BASE_URL_OUTBOUND->value));

        $whatsAppMessageService = new MessageService(
            $this->lead->app,
            $this->lead->company,
            outbound: ! $isFromWhatsapp && $hasOutboundConfigured
        );

        $cellphone = ($to !== null && $to !== '')
            ? $to
            : $this->lead->people->getCellPhones()->first()?->value;

        if ($cellphone === null || $cellphone === '') {
            throw new LeadMissingContactException('Lead does not have a cellphone number');
        }
        $cellphone = $this->hijackPhoneNumber((string) $cellphone, '@s.whatsapp.net');

        $this->sendWhatsAppMediaFiles($whatsAppMessageService, $cellphone);

        return $whatsAppMessageService->sendTextMessage($cellphone, $message);
    }

    protected function sendWhatsAppMediaFiles(MessageService $messageService, string $cellphone): void
    {
        if (empty($this->processedFiles)) {
            return;
        }

        $delay = 30;

        foreach ($this->videoEngagements as $videoEngagement) {
            if (! empty($videoEngagement['url'])) {
                $messageService->sendTextMessage($cellphone, $videoEngagement['url']);
            }
            sleep($delay);
        }

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                continue;
            }

            $type = $file['type'] ?? null;
            if (! $type instanceof MediaTypeEnum) {
                continue;
            }

            sleep($delay);

            match ($type) {
                MediaTypeEnum::IMAGE => $messageService->sendImageMessage($cellphone, $file['url']),
                MediaTypeEnum::VIDEO => $messageService->sendVideoMessage($cellphone, $file['url']),
                MediaTypeEnum::AUDIO => $messageService->sendAudioMessage($cellphone, $file['url']),
                MediaTypeEnum::DOCUMENT => $messageService->sendDocumentMessage($cellphone, $file['url'], $file['name'] ?? null),
            };
        }
    }

    protected function isRespondIoEnabled(): bool
    {
        return (bool) $this->lead->company->get(RespondIOConfigurationEnum::ENABLED->value);
    }

    protected function getRespondIoClient(): RespondIOClient
    {
        return new RespondIOClient($this->lead->app, $this->lead->company);
    }

    protected function sendRespondIoMessage(string $message, ?string $to = null): array
    {
        $client = $this->getRespondIoClient();

        $cellphone = ($to !== null && $to !== '')
            ? $to
            : $this->lead->people->getCellPhones()->first()?->value;

        if ($cellphone === null || $cellphone === '') {
            throw new LeadMissingContactException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber((string) $cellphone, '@s.whatsapp.net');
        $cellphone = Str::toE164($cellphone);

        $responses = [];

        foreach ($this->videoEngagements as $videoEngagement) {
            if (! empty($videoEngagement['url'])) {
                $responses[] = $client->sendMessage($cellphone, $videoEngagement['url']);
            }
        }

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                continue;
            }

            $type = $file['type'] ?? null;
            if (! $type instanceof MediaTypeEnum) {
                continue;
            }

            $attachmentType = match ($type) {
                MediaTypeEnum::IMAGE => 'image',
                MediaTypeEnum::VIDEO => 'video',
                MediaTypeEnum::AUDIO => 'audio',
                MediaTypeEnum::DOCUMENT => 'file',
                default => null,
            };

            if ($attachmentType === null) {
                continue;
            }

            $responses[] = $client->sendAttachment($cellphone, $attachmentType, $file['url']);
        }

        if ($message !== '') {
            $responses[] = $client->sendMessage($cellphone, $message);
        }

        return [
            'channel' => 'respondio',
            'to' => $cellphone,
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => $responses,
        ];
    }

    protected function sendSmsMessage(string $from, string $message, ?string $to = null): array
    {
        // if ($this->isRespondIoEnabled()) {
        //     return $this->sendRespondIoMessage($message, $to);
        // }

        $client = Client::getInstanceByCompany($this->lead->company);

        $cellphone = ($to !== null && $to !== '')
            ? $to
            : $this->lead->people->getCellPhones()->first()?->value;

        if ($cellphone === null || $cellphone === '') {
            throw new LeadMissingContactException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber((string) $cellphone, 'twilio-');
        $cellphone = Str::toE164($cellphone);

        $engagementUrls = array_filter(array_column($this->videoEngagements, 'url'));
        $fullMessage = $message;
        if (! empty($engagementUrls)) {
            $fullMessage .= "\n\n" . implode("\n", $engagementUrls);
        }

        $mediaUrls = $this->getMediaUrlsForTwilio();

        if (empty($mediaUrls)) {
            $payload = ['from' => $from];
            if ($fullMessage !== '') {
                $payload['body'] = $fullMessage;
            }

            $twilioMessage = $client->messages->create($cellphone, $payload);

            return [
                'channel' => 'sms',
                'batches' => 1,
                'batch_size' => 0,
                'media_per_batch' => [0],
                'lead_id' => $this->lead->getId(),
                'lead_uuid' => $this->lead->uuid,
                'messages' => [$this->describeTwilioMessage($twilioMessage)],
            ];
        }

        $batchSize = (int) ($this->lead->app->get(TwilioConfigurationEnum::TWILIO_MMS_BATCH_SIZE->value) ?: self::DEFAULT_MMS_BATCH_SIZE);
        $batchSize = max(1, min(self::MAX_MMS_BATCH_SIZE, $batchSize));

        $batches = array_chunk($mediaUrls, $batchSize);
        $twilioMessages = [];

        foreach ($batches as $index => $batch) {
            $payload = [
                'from' => $from,
                'mediaUrl' => $batch,
            ];

            if ($index === 0 && $fullMessage !== '') {
                $payload['body'] = $fullMessage;
            }

            $twilioMessages[] = $client->messages->create($cellphone, $payload);
        }

        return [
            'channel' => 'sms',
            'batches' => count($batches),
            'batch_size' => $batchSize,
            'media_per_batch' => array_map('count', $batches),
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => array_map(fn ($m) => $this->describeTwilioMessage($m), $twilioMessages),
        ];
    }

    private function describeTwilioMessage(object $twilioMessage): array
    {
        return [
            'sid' => $twilioMessage->sid,
            'account_sid' => $twilioMessage->accountSid,
            'messaging_service_sid' => $twilioMessage->messagingServiceSid,
            'status' => $twilioMessage->status,
            'direction' => $twilioMessage->direction,
            'from' => $twilioMessage->from,
            'to' => $twilioMessage->to,
            'body' => $twilioMessage->body,
            'num_segments' => $twilioMessage->numSegments,
            'num_media' => $twilioMessage->numMedia,
            'error_code' => $twilioMessage->errorCode,
            'error_message' => $twilioMessage->errorMessage,
            'price' => $twilioMessage->price,
            'price_unit' => $twilioMessage->priceUnit,
            'date_created' => $twilioMessage->dateCreated?->format(DateTimeInterface::ATOM),
            'date_sent' => $twilioMessage->dateSent?->format(DateTimeInterface::ATOM),
            'date_updated' => $twilioMessage->dateUpdated?->format(DateTimeInterface::ATOM),
            'uri' => $twilioMessage->uri,
        ];
    }

    protected function getMediaUrlsForTwilio(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $mediaUrls = [];
        $maxUrls = (int) ($this->lead->app->get(TwilioConfigurationEnum::TWILIO_MMS_MAX_TOTAL_MEDIA->value) ?: self::DEFAULT_MMS_MAX_TOTAL_MEDIA);
        $maxUrls = max(1, $maxUrls);

        foreach ($this->videoEngagements as $videoEngagement) {
            if (count($mediaUrls) >= $maxUrls) {
                break;
            }
            if (! empty($videoEngagement['gif_url'])) {
                $mediaUrls[] = $videoEngagement['gif_url'];
            }
        }

        foreach ($this->processedFiles as $file) {
            if (count($mediaUrls) >= $maxUrls) {
                break;
            }

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

        $engagementUrls = array_filter(array_column($this->videoEngagements, 'url'));
        if (! empty($engagementUrls)) {
            $message .= "\n\n" . implode("\n", $engagementUrls);
        }

        // Agent replies are Markdown; the mail layout renders raw HTML, so convert here.
        $message = MarkdownEmailRenderer::toEmailHtml($message);

        foreach ($this->processedFiles as $file) {
            if (isset($file['is_processed_video']) && $file['is_processed_video']) {
                continue;
            }
            if (isset($file['url'])) {
                $attachments[] = $file['url'];
            }
        }

        $notification = new Blank(
            'first-time-agent-engagement',
            [
                'content' => $message,
                'lead' => $this->lead,
                'noHi' => true,
                'company' => $this->lead->company,
                'signature' => $signature,
            ],
            ['mail'],
            $this->lead,
            ! empty($attachments) ? $attachments : null
        );
        $notification->setFromUser($this->lead->user);
        $notification->setSubject($title ?? 'Message from ' . $this->lead->company->name);
        $ccList = array_values(array_filter($cc ?? []));
        if ($ccList !== []) {
            $notification->setCc($ccList);
        }
        $leadEmail = ($to !== null && $to !== '')
            ? $to
            : $this->lead->people->getEmails()->first()?->value;
        if (! $leadEmail) {
            throw new LeadMissingContactException('Lead does not have an email address');
        }
        Notification::route('mail', $leadEmail)->notify($notification);

        return [
          'channel' => 'email',
          'to' => $leadEmail,
          'cc' => $ccList,
          'template' => 'first-time-agent-engagement',
          'body_length' => strlen($message),
          'signature' => $signature,
          'engagement_urls' => array_values($engagementUrls),
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

    /**
     * Get attachment URLs for email.
     */
    protected function getAttachmentUrlsForEmail(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $attachments = [];

        foreach ($this->processedFiles as $file) {
            $attachments[] = $file['url'];
        }

        return $attachments;
    }

    protected function hijackPhoneNumber(string $cellphone, string $replace): string
    {
        if ($this->lead->company->get('allow_session_hijack', false)
          && $this->lead->company->get('overwrite_phone_number') !== null
        ) {
            $overwriteConfig = $this->lead->company->get('overwrite_phone_number');

            $phone = array_filter($overwriteConfig, function ($value) use ($cellphone) {
                return Str::normalizePhoneNumber($cellphone);
            });
            if (! $phone) {
                throw new Exception('No hijack number found for this phone number');
            }
            $cellphone = array_keys($phone)[0];
            $cellphone = str_replace($replace, '', $cellphone);
        }

        return $cellphone;
    }
}
