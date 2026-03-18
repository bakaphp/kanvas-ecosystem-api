<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum as WaSenderConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Actions\ProcessVideoWithGifAction;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Templates\Blank;
use Ramsey\Uuid\Uuid;

class SendMessageToLeadAction
{
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
        ?Collection $files = null
    ): array {
        if ($files !== null && $files->isNotEmpty()) {
            $this->processedFiles = $this->prepareFiles($files);
            $this->createVideoEngagements();
        }

        return match ($channel) {
            LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message),
            LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage($from, $message),
            LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage($message, $title, $signature),
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
                    action: 'message-video',
                    requestId: Uuid::uuid4()->toString(),
                    source: 'video-message',
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

    protected function sendWhatsAppMessage(string $message): array
    {
        $isFromWhatsapp = (bool) $this->lead->get(ConfigurationEnum::IS_FROM_WHATSAPP->value);
        $hasOutboundConfigured = ! empty($this->lead->app->get(WaSenderConfigurationEnum::BASE_URL_OUTBOUND->value));

        $whatsAppMessageService = new MessageService(
            $this->lead->app,
            $this->lead->company,
            outbound: ! $isFromWhatsapp && $hasOutboundConfigured
        );

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }
        $cellphone = $this->hijackPhoneNumber($cellphone, '@s.whatsapp.net');

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

    protected function sendSmsMessage(string $from, string $message): array
    {
        $client = Client::getInstanceByCompany($this->lead->company);

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber($cellphone, 'twilio-');

        $engagementUrls = array_filter(array_column($this->videoEngagements, 'url'));
        $fullMessage = $message;
        if (! empty($engagementUrls)) {
            $fullMessage .= "\n\n" . implode("\n", $engagementUrls);
        }

        $messageData = [
            'from' => $from,
        ];

        if (! $fullMessage) {
            $messageData['body'] = $fullMessage;
        }

        $mediaUrls = $this->getMediaUrlsForTwilio();
        if (! empty($mediaUrls)) {
            $messageData['mediaUrl'] = $mediaUrls;
        }

        $twilioMessage = $client->messages->create($cellphone, $messageData);

        return [$twilioMessage->body];
    }

    protected function getMediaUrlsForTwilio(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $mediaUrls = [];
        $maxUrls = 10;

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
        bool $signature = true
    ): array {
        $attachments = [];

        $engagementUrls = array_filter(array_column($this->videoEngagements, 'url'));
        if (! empty($engagementUrls)) {
            $message .= "\n\n" . implode("\n", $engagementUrls);
        }

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
        $leadEmail = $this->lead->people->getEmails()->first()?->value;
        if (! $leadEmail) {
            throw new Exception('Lead does not have an email address');
        }
        Notification::route('mail', $leadEmail)->notify($notification);

        return [];
    }

    protected function sendVoiceMessage(string $instructions = ''): array
    {
        $agent = Agent::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->where('name', 'voiceOutreachAgent')
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
