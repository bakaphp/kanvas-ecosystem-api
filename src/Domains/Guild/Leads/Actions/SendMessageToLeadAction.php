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
use Kanvas\ActionEngine\Engagements\Models\Engagement as EngagementModel;
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
        }

        return match ($channel) {
            LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message),
            LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage($from, $message),
            LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage($message, $title, $signature),
            LeadCommunicationChannelEnum::VOICE->value => $this->sendVoiceMessage(),
            default => throw new InvalidArgumentException('Unsupported communication channel ' . $channel),
        };
    }

    /**
     * Prepare files for sending.
     */
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
            ];

            if ($mediaType->isVideo()) {
                $processedVideo = new ProcessVideoWithGifAction(
                    $this->lead->app,
                    $this->lead->company,
                    $this->lead->user,
                    $file->url
                )->execute();
                if ($processedVideo !== null) {
                    $processed[] = $processedVideo['video'];
                    $processed[] = $processedVideo['gif'];
                } else {
                    $processed[] = $fileData;
                }
            } else {
                $processed[] = $fileData;
            }
        }

        return $processed;
    }

    /**
     * Get processed video and GIF filesystem records.
     *
     * @return array<Filesystem>
     */
    public function getProcessedFilesystems(): array
    {
        $filesystems = [];

        foreach ($this->processedFiles as $file) {
            if (isset($file['filesystem'])) {
                $filesystems[] = $file['filesystem'];
            }
        }

        return $filesystems;
    }

    /**
     * Get processed video and GIF URLs for engagement.
     *
     * @return array<string>
     */
    public function getProcessedFilesUrls(): array
    {
        $urls = [];

        foreach ($this->processedFiles as $file) {
            if (isset($file['filesystem'])) {
                $urls[] = $file['url'];
            }
        }

        return $urls;
    }

    /**
     * Get files grouped by media type.
     */
    protected function getFilesGroupedByType(): array
    {
        $grouped = [
            MediaTypeEnum::IMAGE->value => [],
            MediaTypeEnum::VIDEO->value => [],
            MediaTypeEnum::AUDIO->value => [],
            MediaTypeEnum::DOCUMENT->value => [],
        ];

        foreach ($this->processedFiles as $file) {
            $type = $file['type']->value;
            if (isset($grouped[$type])) {
                $grouped[$type][] = $file;
            }
        }

        return $grouped;
    }

    protected function sendWhatsAppMessage(string $message): array
    {
        $isFromWhatsapp = (bool) $this->lead->get(ConfigurationEnum::IS_FROM_WHATSAPP->value);
        $hasOutboundConfigured = ! empty($this->lead->app->get(WaSenderConfigurationEnum::BASE_URL_OUTBOUND->value));

        if (! $isFromWhatsapp && $hasOutboundConfigured) {
            return $this->sendWhatsappMessageByOutbound($message);
        }

        $whatsAppMessageService = new MessageService(
            $this->lead->app,
            $this->lead->company
        );

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }
        $cellphone = $this->hijackPhoneNumber($cellphone, '@s.whatsapp.net');

        $this->sendWhatsAppMediaFiles($whatsAppMessageService, $cellphone);

        $result = $whatsAppMessageService->sendTextMessage($cellphone, $message);

        return $result;
    }

    protected function sendWhatsappMessageByOutbound(string $message): array
    {
        $whatsAppMessageService = new MessageService(
            $this->lead->app,
            $this->lead->company,
            outbound: true
        );

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }
        $cellphone = $this->hijackPhoneNumber($cellphone, '@s.whatsapp.net');

        $this->sendWhatsAppMediaFiles($whatsAppMessageService, $cellphone);
        $result = $whatsAppMessageService->sendTextMessage($cellphone, $message);

        return $result;
    }

    protected function sendWhatsAppMediaFiles(MessageService $messageService, string $cellphone): void
    {
        if (empty($this->processedFiles)) {
            return;
        }

        $groupedFiles = $this->getFilesGroupedByType();
        $delay = 30; // seconds before each file to avoid API rate limiting

        // Process videos first - create engagement and send URL instead of video
        foreach ($groupedFiles[MediaTypeEnum::VIDEO->value] as $file) {
            if (isset($file['filesystem'])) {
                // Video was processed, create engagement and send URL
                $engagement = $this->createVideoEngagement($file);
                if ($engagement !== null) {
                    $engagementUrl = $this->getEngagementUrl($engagement);
                    $messageService->sendTextMessage($cellphone, $engagementUrl);
                }
            } else {
                // Video not processed, send directly
                $messageService->sendVideoMessage($cellphone, $file['url']);
            }
            sleep($delay);
        }

        // Send images (including generated GIFs)
        foreach ($groupedFiles[MediaTypeEnum::IMAGE->value] as $file) {
            sleep($delay);
            $messageService->sendImageMessage($cellphone, $file['url']);
        }

        foreach ($groupedFiles[MediaTypeEnum::DOCUMENT->value] as $file) {
            sleep($delay);
            $messageService->sendDocumentMessage($cellphone, $file['url'], $file['name'] ?? null);
        }

        foreach ($groupedFiles[MediaTypeEnum::AUDIO->value] as $file) {
            sleep($delay);
            $messageService->sendAudioMessage($cellphone, $file['url']);
        }
    }

    /**
     * Create an engagement for video files.
     */
    protected function createVideoEngagement(array $videoFile): ?EngagementModel
    {
        try {
            $filesystems = $this->getProcessedFilesystems();

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

            return new CreateEngagementAction($engagementData)->execute();
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get the engagement URL for sharing.
     */
    protected function getEngagementUrl(EngagementModel $engagement): string
    {
        return $engagement->message->message['action_link'] ?? '';
    }

    protected function sendSmsMessage(string $from, string $message): array
    {
        $client = Client::getInstanceByCompany($this->lead->company);

        $cellphone = $this->lead->people->getCellPhones()->first()?->value;

        if (! $cellphone) {
            throw new InvalidArgumentException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber($cellphone, 'twilio-');

        // Process videos and get engagement URLs
        $videoEngagementUrls = $this->processVideosForSms();
        $fullMessage = $message;
        if (! empty($videoEngagementUrls)) {
            $fullMessage .= "\n\n" . implode("\n", $videoEngagementUrls);
        }

        $messageData = [
            'from' => $from,
            'body' => $fullMessage,
        ];

        $mediaUrls = $this->getMediaUrlsForTwilio();
        if (! empty($mediaUrls)) {
            $messageData['mediaUrl'] = $mediaUrls;
        }

        $twilioMessage = $client->messages->create($cellphone, $messageData);

        return [$twilioMessage->body];
    }

    /**
     * Process videos for SMS and return engagement URLs.
     *
     * @return array<string>
     */
    protected function processVideosForSms(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $groupedFiles = $this->getFilesGroupedByType();
        $engagementUrls = [];

        foreach ($groupedFiles[MediaTypeEnum::VIDEO->value] as $file) {
            if (isset($file['filesystem'])) {
                $engagement = $this->createVideoEngagement($file);
                if ($engagement !== null) {
                    $engagementUrl = $this->getEngagementUrl($engagement);
                    if (! empty($engagementUrl)) {
                        $engagementUrls[] = $engagementUrl;
                    }
                }
            }
        }

        return $engagementUrls;
    }

    /**
     * Get media URLs for Twilio MMS (max 10 per message).
     * Excludes processed videos since they are sent as engagement URLs.
     */
    protected function getMediaUrlsForTwilio(): array
    {
        if (empty($this->processedFiles)) {
            return [];
        }

        $groupedFiles = $this->getFilesGroupedByType();
        $mediaUrls = [];
        $maxUrls = 10;

        foreach ($groupedFiles[MediaTypeEnum::IMAGE->value] as $file) {
            if (count($mediaUrls) >= $maxUrls) {
                break;
            }
            $mediaUrls[] = $file['url'];
        }

        foreach ($groupedFiles[MediaTypeEnum::AUDIO->value] as $file) {
            if (count($mediaUrls) >= $maxUrls) {
                break;
            }
            $mediaUrls[] = $file['url'];
        }

        return $mediaUrls;
    }

    protected function sendEmailMessage(
        string $message,
        ?string $title = null,
        bool $signature = true
    ): array {
        $attachments = $this->getAttachmentUrlsForEmail();

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

    protected function sendVoiceMessage(): array
    {
        $agent = Agent::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->where('name', 'voiceOutreachAgent')
            ->firstOrFail();

        $sessionResult = InitVoiceSessionAction::fromLead($this->lead, $agent)->execute();
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
                //return preg_replace('/^\+?1/', '', $cellphone);
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
