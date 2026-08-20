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
use Kanvas\Connectors\Twilio\Actions\RecordMessageAttemptAction;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\Twilio\Services\MessageErrorClassifier;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Connectors\VoiceBridge\Actions\InitVoiceSessionAction;
use Kanvas\Connectors\VoiceBridge\Actions\TriggerVoiceCallAction;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum as WaSenderConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Filesystem\Actions\ProcessVideoWithGifAction;
use Kanvas\Filesystem\Enums\MediaTypeEnum;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Exceptions\LeadOptedOutException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Notifications\Support\MarkdownEmailRenderer;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Ramsey\Uuid\Uuid;
use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client as TwilioClient;

class SendMessageToLeadAction
{
    private const int TWILIO_UNSUBSCRIBED_RECIPIENT_CODE = 21610;
    private const int DEFAULT_TWILIO_MAX_MESSAGE_BODY_LENGTH = 1600;
    private const array APPROVED_A2P_REGISTRATION_STATUSES = ['approved', 'registered', 'verified', 'active'];

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
    protected string $attemptUuid;
    protected ?string $attemptedFrom = null;
    protected ?string $attemptedTo = null;
    protected int $retryNumber = 0;
    protected ?int $parentAttemptId = null;

    public function __construct(
        protected Lead $lead,
    ) {
        $this->attemptUuid = Uuid::uuid4()->toString();
    }

    public function withRetryContext(int $parentAttemptId, int $retryNumber = 1): self
    {
        $this->parentAttemptId = $parentAttemptId;
        $this->retryNumber = $retryNumber;

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
        ?array $cc = null
    ): array {
        try {
            if ($files !== null && $files->isNotEmpty()) {
                $this->processedFiles = $this->prepareFiles($files);
                $this->createVideoEngagements();
            }

            $result = match ($channel) {
                LeadCommunicationChannelEnum::WHATSAPP->value => $this->sendWhatsAppMessage($message, $to),
                LeadCommunicationChannelEnum::SMS->value => $this->sendSmsMessage(
                    $this->resolveTwilioFrom($from),
                    $message,
                    $to,
                ),
                LeadCommunicationChannelEnum::EMAIL->value => $this->sendEmailMessage($message, $title, $signature, $to, $cc),
                LeadCommunicationChannelEnum::VOICE->value => $this->sendVoiceMessage($message),
                default => throw new InvalidArgumentException('Unsupported communication channel ' . $channel),
            };

            if ($channel === LeadCommunicationChannelEnum::SMS->value) {
                $result['attempt_uuid'] = $this->attemptUuid;
                $result['parent_attempt_id'] = $this->parentAttemptId;
                $result['retry_number'] = $this->retryNumber;
                $this->recordTwilioAttempt($result);
            }

            return $result;
        } catch (Throwable $exception) {
            if (MessageErrorClassifier::classify($exception)['retryable']) {
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

        $classification = MessageErrorClassifier::classify($exception);

        $result = array_merge([
            'status' => 'error',
            'success' => false,
            'channel' => $channel,
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [],
            'error' => $exception->getMessage(),
            'attempt_uuid' => $this->attemptUuid,
            'account_sid' => $this->lead->company?->get(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value),
            'from' => $this->attemptedFrom,
            'to' => $this->attemptedTo,
            'parent_attempt_id' => $this->parentAttemptId,
            'retry_number' => $this->retryNumber,
        ], $classification);

        if ($channel === LeadCommunicationChannelEnum::SMS->value) {
            $this->recordTwilioAttempt($result);
        }

        return $result;
    }

    protected function isExpectedLeadCondition(Throwable $exception): bool
    {
        return $exception instanceof LeadOptedOutException
            || $exception instanceof LeadMissingContactException;
    }

    protected function recordTwilioAttempt(array $providerResponse): void
    {
        if (! $this->lead->exists) {
            return;
        }

        new RecordMessageAttemptAction(
            app: $this->lead->app,
            company: $this->lead->company,
            leadId: $this->lead->getId(),
        )->execute($providerResponse);
    }

    protected function resolveTwilioFrom(?string $from): string
    {
        if ($from !== null && $from !== '') {
            return $from;
        }

        return (string) (
            $this->lead->company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $this->lead->company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value)
            ?? ''
        );
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

        $route = $this->validateTwilioSenderRoute($from);
        $client = Client::getInstanceByCompany($this->lead->company);

        $cellphone = ($to !== null && $to !== '')
            ? $to
            : $this->lead->people->getCellPhones()->first()?->value;

        if ($cellphone === null || $cellphone === '') {
            throw new LeadMissingContactException('Lead does not have a cellphone number');
        }

        $cellphone = $this->hijackPhoneNumber((string) $cellphone, 'twilio-');
        $cellphone = Str::toE164($cellphone);
        $this->attemptedFrom = $route['from'] ?? null;
        $this->attemptedTo = $cellphone;
        $this->guardSmsDestination($cellphone, $route['from'] ?? null);
        $cellphone = $this->lookupPhoneNumber($client, $cellphone);

        $engagementUrls = array_filter(array_column($this->videoEngagements, 'url'));
        $fullMessage = $message;
        if (! empty($engagementUrls)) {
            $fullMessage .= "\n\n" . implode("\n", $engagementUrls);
        }

        $this->validateTwilioMessageBody($fullMessage);

        $mediaUrls = $this->getMediaUrlsForTwilio();

        try {
            return $this->sendTwilioMessages($client, $cellphone, $route, $fullMessage, $mediaUrls);
        } catch (RestException $exception) {
            if ($exception->getCode() !== self::TWILIO_UNSUBSCRIBED_RECIPIENT_CODE) {
                throw $exception;
            }

            return $this->handleUnsubscribedRecipient($cellphone, $fullMessage);
        }
    }

    protected function sendTwilioMessages(
        TwilioClient $client,
        string $cellphone,
        array $route,
        string $fullMessage,
        array $mediaUrls,
    ): array {
        if (empty($mediaUrls)) {
            $payload = $route;
            if ($fullMessage !== '') {
                $payload['body'] = $fullMessage;
            }

            $twilioMessage = $this->createTwilioMessage($client, $cellphone, $payload);

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
            $payload = $route + ['mediaUrl' => $batch];

            if ($index === 0 && $fullMessage !== '') {
                $payload['body'] = $fullMessage;
            }

            $twilioMessages[] = $this->createTwilioMessage($client, $cellphone, $payload);
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

    protected function handleUnsubscribedRecipient(string $cellphone, string $body): array
    {
        $this->lead->people?->setPhoneOptOut($cellphone);

        $this->recordOptOutNote($cellphone, $body);

        return [
            'status' => 'failed',
            'success' => false,
            'channel' => 'sms',
            'batches' => 0,
            'batch_size' => 0,
            'media_per_batch' => [],
            'lead_id' => $this->lead->getId(),
            'lead_uuid' => $this->lead->uuid,
            'messages' => [],
            'opted_out' => true,
            'classification' => 'opted_out',
            'retryable' => false,
            'twilio_error_code' => self::TWILIO_UNSUBSCRIBED_RECIPIENT_CODE,
            'error' => 'Attempt to send to unsubscribed recipient',
            'account_sid' => $this->lead->company?->get(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value),
            'from' => $this->attemptedFrom,
            'to' => $cellphone,
        ];
    }

    protected function recordOptOutNote(string $cellphone, string $body): void
    {
        new RecordLeadNoteAction($this->lead)->execute(
            "SMS not delivered: {$cellphone} has opted out of messages (replied STOP). Attempted message: \"{$body}\"",
            'sms-opt-out',
        );
    }

    /**
     * Resolve and validate the locally-authoritative sender route before any
     * destination Lookup or Messages API call. This prevents deterministic
     * 21603/21660 failures without adding a per-message Twilio API request.
     *
     * @return array{from: string}|array{messagingServiceSid: string}
     */
    protected function validateTwilioSenderRoute(string $from): array
    {
        $company = $this->lead->company;
        $messagingServiceSid = trim((string) ($company->get(
            TwilioConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value
        ) ?? ''));
        $from = trim($from);

        if ($from === '' && $messagingServiceSid === '') {
            throw new InvalidArgumentException(
                'Twilio sender route is missing: configure a From number or MessagingServiceSid (prevents error 21603)'
            );
        }

        $accountSid = trim((string) ($company->get(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value) ?? ''));
        $senderAccountSid = trim((string) ($company->get(
            TwilioConfigurationEnum::TWILIO_SENDER_ACCOUNT_SID->value
        ) ?? ''));

        if ($senderAccountSid !== '' && ! hash_equals($accountSid, $senderAccountSid)) {
            throw new InvalidArgumentException(
                'Twilio sender route belongs to a different account/subaccount (prevents error 21660)'
            );
        }

        if ($from !== '') {
            $normalizedFrom = Str::toE164($from);
            $allowedFromNumbers = $this->configuredStringList(
                $company->get(TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value)
            );

            if ($allowedFromNumbers !== []) {
                $allowedFromNumbers = array_map(
                    static fn (string $number): string => Str::toE164($number),
                    $allowedFromNumbers,
                );

                if (! in_array($normalizedFrom, $allowedFromNumbers, true)) {
                    throw new InvalidArgumentException(
                        'Twilio From number is not registered for this dealership/account (prevents sender leakage and error 21660)'
                    );
                }
            }

            $this->validateA2pRegistration($normalizedFrom);

            return ['from' => $normalizedFrom];
        }

        if (! str_starts_with($messagingServiceSid, 'MG')) {
            throw new InvalidArgumentException('Twilio MessagingServiceSid must start with MG');
        }

        $this->validateA2pRegistration(null);

        return ['messagingServiceSid' => $messagingServiceSid];
    }

    protected function validateTwilioMessageBody(string $message): void
    {
        $configuredLimit = (int) ($this->lead->company->get(
            TwilioConfigurationEnum::TWILIO_MAX_MESSAGE_BODY_LENGTH->value
        ) ?: self::DEFAULT_TWILIO_MAX_MESSAGE_BODY_LENGTH);
        $limit = max(1, min(self::DEFAULT_TWILIO_MAX_MESSAGE_BODY_LENGTH, $configuredLimit));

        if (mb_strlen($message, 'UTF-8') > $limit) {
            throw new InvalidArgumentException(sprintf(
                'Twilio message body exceeds the configured carrier-safe limit of %d characters (prevents avoidable error 30019)',
                $limit,
            ));
        }
    }

    protected function validateA2pRegistration(?string $from): void
    {
        if ($from !== null && ! str_starts_with($from, '+1')) {
            return;
        }

        $enforceRegistration = filter_var(
            $this->lead->company->get(TwilioConfigurationEnum::TWILIO_ENFORCE_A2P_REGISTRATION->value),
            FILTER_VALIDATE_BOOL,
        );
        if (! $enforceRegistration) {
            return;
        }

        $status = strtolower(trim((string) ($this->lead->company->get(
            TwilioConfigurationEnum::TWILIO_A2P_REGISTRATION_STATUS->value
        ) ?? '')));

        if (! in_array($status, self::APPROVED_A2P_REGISTRATION_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Twilio A2P sender registration is %s; outbound SMS is blocked to prevent error 30034',
                $status !== '' ? $status : 'missing',
            ));
        }
    }

    /** @return list<string> */
    private function configuredStringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        )));
    }

    protected function lookupPhoneNumber(TwilioClient $client, string $cellphone): string
    {
        $phoneNumber = $client->lookups->v2->phoneNumbers($cellphone)->fetch([
            'fields' => 'line_type_intelligence,line_status',
        ]);

        if (! $phoneNumber->valid) {
            throw new LeadMissingContactException(
                sprintf('Lead cellphone number %s is not valid', $cellphone)
            );
        }

        $lineType = strtolower((string) ($phoneNumber->lineTypeIntelligence['type'] ?? ''));
        if ($lineType === 'landline') {
            throw new LeadMissingContactException(
                sprintf('Lead cellphone number %s is a landline and cannot receive SMS messages', $cellphone)
            );
        }

        if ($lineType === 'premium') {
            throw new LeadMissingContactException(
                sprintf(
                    'Lead cellphone number %s is a premium line and cannot receive SMS messages',
                    $cellphone
                )
            );
        }

        $lineStatus = strtolower((string) ($phoneNumber->lineStatus['status'] ?? 'unknown'));
        if (in_array($lineStatus, ['inactive', 'unreachable'], true)) {
            throw new LeadMissingContactException(
                sprintf('Lead cellphone number %s is %s and cannot receive SMS messages', $cellphone, $lineStatus)
            );
        }

        return (string) ($phoneNumber->phoneNumber ?? $cellphone);
    }

    protected function createTwilioMessage(TwilioClient $client, string $cellphone, array $payload): object
    {
        $statusCallbackUrl = $this->getTwilioStatusCallbackUrl();
        if ($statusCallbackUrl !== null) {
            $payload['statusCallback'] = $statusCallbackUrl;
        }

        return $client->messages->create($cellphone, $payload);
    }

    protected function guardSmsDestination(string $cellphone, ?string $from): void
    {
        $normalizedFrom = $from !== null ? Str::toE164($from) : null;
        if ($normalizedFrom !== null && $normalizedFrom === $cellphone) {
            throw new InvalidArgumentException('Twilio To and From numbers must be different');
        }

        $isOptedOut = $this->lead->people?->getAllPhones()->contains(
            fn ($contact): bool => Contact::normalizeValue(
                (string) $contact->value,
                (int) $contact->contacts_types_id,
            ) === Contact::normalizeValue(
                $cellphone,
                ContactTypeEnum::CELLPHONE->value,
            ) && (int) $contact->is_opt_out === 1,
        ) ?? false;

        if ($isOptedOut) {
            throw new LeadOptedOutException('Destination phone has opted out of SMS communications');
        }
    }

    protected function getTwilioStatusCallbackUrl(): ?string
    {
        $receiver = ReceiverWebhook::query()
            ->fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->notDeleted()
            ->where('is_active', true)
            ->whereHas(
                'action',
                fn ($query) => $query->where('model_name', ProcessTwilioMessageStatusWebhookJob::class),
            )
            ->first();

        return $receiver?->getUrl();
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
