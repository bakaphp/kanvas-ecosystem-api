<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum as WaSenderConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;

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

            $processed[] = $fileData;
        }

        return $processed;
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

        $result = $whatsAppMessageService->sendTextMessage($cellphone, $message);

        $this->sendWhatsAppMediaFiles($whatsAppMessageService, $cellphone);

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

        $result = $whatsAppMessageService->sendTextMessage($cellphone, $message);

        $this->sendWhatsAppMediaFiles($whatsAppMessageService, $cellphone);

        return $result;
    }

    protected function sendWhatsAppMediaFiles(MessageService $messageService, string $cellphone): void
    {
        if (empty($this->processedFiles)) {
            return;
        }

        $groupedFiles = $this->getFilesGroupedByType();
        $delay = 30; // seconds between each file to avoid API rate limiting

        foreach ($groupedFiles[MediaTypeEnum::IMAGE->value] as $file) {
            $messageService->sendImageMessage($cellphone, $file['url']);
            sleep($delay);
        }

        foreach ($groupedFiles[MediaTypeEnum::VIDEO->value] as $file) {
            $messageService->sendVideoMessage($cellphone, $file['url']);
            sleep($delay);
        }

        foreach ($groupedFiles[MediaTypeEnum::DOCUMENT->value] as $file) {
            $messageService->sendDocumentMessage($cellphone, $file['url'], $file['name'] ?? null);
            sleep($delay);
        }

        foreach ($groupedFiles[MediaTypeEnum::AUDIO->value] as $file) {
            $messageService->sendAudioMessage($cellphone, $file['url']);
            sleep($delay);
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

        $messageData = [
            'from' => $from,
            'body' => $message,
        ];

        $mediaUrls = $this->getMediaUrlsForTwilio();
        if (! empty($mediaUrls)) {
            $messageData['mediaUrl'] = $mediaUrls;
        }

        $twilioMessage = $client->messages->create($cellphone, $messageData);

        return [$twilioMessage->body];
    }

    /**
     * Get media URLs for Twilio MMS (max 10 per message).
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

        foreach ($groupedFiles[MediaTypeEnum::VIDEO->value] as $file) {
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
