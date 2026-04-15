<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Illuminate\Http\UploadedFile;
use Kanvas\Connectors\ElevenLabs\Enums\CustomFieldEnum;
use Kanvas\Connectors\ElevenLabs\Enums\WebhookTypeEnum;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessElevenLabsTranscriptWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;

        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? $payload);

        if ($type === WebhookTypeEnum::POST_CALL_AUDIO->value || isset($data['full_audio'])) {
            return $this->handleAudio($data);
        }

        if ($type === WebhookTypeEnum::CALL_INITIATION_FAILURE->value || isset($data['failure_reason'])) {
            return $this->handleCallFailure($data);
        }

        return $this->handleTranscription($data);
    }

    protected function handleTranscription(array $data): array
    {
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $transcript = (array) ($data['transcript'] ?? []);
        $metadata = (array) ($data['metadata'] ?? []);
        $analysis = (array) ($data['analysis'] ?? []);

        $phone = $this->extractPhoneFromPayload($data);
        $lead = $this->findLeadByPhone($phone);

        if (! $lead) {
            return [
                'message' => 'No lead found for phone: ' . $phone,
                'conversation_id' => $conversationId,
            ];
        }

        $this->updateLeadPeopleFromAnalysis($lead, $analysis);

        $app = $this->receiver->app;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $app->getId(),
                name: 'ElevenLabs Voice Transcript',
                verb: 'elevenlabs-voice-transcript',
            )
        )->execute();

        $formattedTranscript = $this->formatTranscript($transcript);
        $dataCollectionResults = (array) ($analysis['data_collection_results'] ?? []);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $this->receiver->company,
                user: $this->receiver->user,
                type: $messageType,
                message: [
                    'transcript' => $formattedTranscript,
                    'conversation_id' => $conversationId,
                    'agent_id' => $data['agent_id'] ?? null,
                    'agent_name' => $data['agent_name'] ?? null,
                    'call_duration_secs' => $metadata['call_duration_secs'] ?? null,
                    'call_successful' => $analysis['call_successful'] ?? null,
                    'transcript_summary' => $analysis['transcript_summary'] ?? null,
                    'call_summary_title' => $analysis['call_summary_title'] ?? null,
                    'termination_reason' => $metadata['termination_reason'] ?? null,
                    'data_collection_results' => $dataCollectionResults,
                    'evaluation_criteria_results' => $analysis['evaluation_criteria_results'] ?? null,
                ],
                is_public: 1,
                slug: 'elevenlabs-' . $conversationId,
            ),
        )->execute();

        $message->addEntity($lead);

        $notesChannel = $lead->notes;
        if ($notesChannel) {
            $notesChannel->addMessage($message);
        }

        if ($conversationId !== '') {
            $lead->set(CustomFieldEnum::CONVERSATION_ID->value, $conversationId);
        }

        return [
            'message' => 'Transcript saved',
            'conversation_id' => $conversationId,
            'message_id' => $message->getId(),
            'lead_id' => $lead->getId(),
        ];
    }

    protected function extractPhoneFromPayload(array $data): string
    {
        $metadata = (array) ($data['metadata'] ?? []);
        $phoneCall = $metadata['phone_call'] ?? null;

        if (is_array($phoneCall) && ! empty($phoneCall)) {
            $phone = (string) ($phoneCall['from_number'] ?? $phoneCall['to_number'] ?? '');
            if ($phone !== '') {
                return $phone;
            }
        }

        $analysis = (array) ($data['analysis'] ?? []);
        $dataCollection = (array) ($analysis['data_collection_results'] ?? []);

        if (isset($dataCollection['caller_phone_number']['value'])) {
            $phone = (string) $dataCollection['caller_phone_number']['value'];
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    protected function updateLeadPeopleFromAnalysis(Lead $lead, array $analysis): void
    {
        $dataCollection = (array) ($analysis['data_collection_results'] ?? []);

        $callerName = isset($dataCollection['caller_name']['value'])
            ? (string) $dataCollection['caller_name']['value']
            : null;

        if ($callerName === null || $callerName === '') {
            return;
        }

        /** @var \Kanvas\Guild\Customers\Models\People $people */
        $people = $lead->people;
        $nameParts = explode(' ', $callerName, 2);
        $firstname = $nameParts[0];
        $lastname = $nameParts[1] ?? '';

        $updated = false;

        if ($people->firstname === '' || $people->firstname === $people->contacts()->first()?->value) {
            $people->firstname = $firstname;
            $updated = true;
        }

        if ($lastname !== '' && ($people->lastname === '' || $people->lastname === null)) {
            $people->lastname = $lastname;
            $updated = true;
        }

        if ($updated) {
            $people->saveOrFail();
        }
    }

    protected function handleAudio(array $data): array
    {
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $audioBase64 = (string) ($data['full_audio'] ?? '');

        if ($audioBase64 === '') {
            return [
                'message' => 'No audio data received',
                'conversation_id' => $conversationId,
            ];
        }

        /** @var ?Lead $lead */
        $lead = null;
        /** @var ?Message $existingMessage */
        $existingMessage = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $lead = $this->findLeadByConversationId($conversationId);
            $existingMessage = Message::where('slug', 'elevenlabs-' . $conversationId)
                ->where('companies_id', $this->receiver->company->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if ($lead !== null || $existingMessage !== null) {
                break;
            }

            if ($attempt < 3) {
                sleep(3);
            }
        }

        if ($existingMessage === null && $lead === null) {
            return [
                'message' => 'No transcript or lead found for conversation, audio skipped',
                'conversation_id' => $conversationId,
            ];
        }

        $tempFile = (string) tempnam(sys_get_temp_dir(), 'elevenlabs_audio_') . '.mp3';
        file_put_contents($tempFile, base64_decode($audioBase64));

        try {
            $user = Users::getById($this->receiver->users_id);
            $filesystem = new FilesystemServices($this->receiver->app);
            $file = $filesystem->upload(
                new UploadedFile(
                    $tempFile,
                    'elevenlabs-recording-' . $conversationId . '.mp3',
                    'audio/mpeg',
                    null,
                    true,
                ),
                $user,
            );

            if ($existingMessage !== null) {
                new AttachFilesystemAction($file, $existingMessage)->execute('voice-recording');
            }

            if ($lead !== null) {
                new AttachFilesystemAction($file, $lead)->execute('voice-recording');
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'message' => 'Audio saved',
            'conversation_id' => $conversationId,
            'message_id' => $existingMessage?->getId(),
        ];
    }

    protected function handleCallFailure(array $data): array
    {
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $failureReason = (string) ($data['failure_reason'] ?? 'unknown');
        $failureMetadata = (array) ($data['metadata'] ?? []);
        $body = (array) ($failureMetadata['body'] ?? []);

        $phone = (string) ($body['to_number'] ?? $body['from_number'] ?? '');
        $lead = $this->findLeadByPhone($phone);

        if ($lead) {
            $lead->set(CustomFieldEnum::CALL_FAILURE->value, [
                'conversation_id' => $conversationId,
                'reason' => $failureReason,
                'error_reason' => $body['error_reason'] ?? null,
                'call_sid' => $body['call_sid'] ?? null,
            ]);
        }

        return [
            'message' => 'Call failure recorded',
            'conversation_id' => $conversationId,
            'failure_reason' => $failureReason,
        ];
    }

    protected function findLeadByPhone(string $phone): ?Lead
    {
        if ($phone === '') {
            return null;
        }

        $digitsOnly = Str::sanitizePhoneNumber($phone);
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: array_unique([$digitsOnly, $normalizedPhone]),
        );

        $allCustomers = $query->get();

        $people = $allCustomers->first(function (People $customer): bool {
            return LeadsRepository::getPeopleActiveLead($customer) !== null;
        }) ?? $allCustomers->first();

        if (! $people) {
            return null;
        }

        return LeadsRepository::getPeopleActiveLead($people);
    }

    protected function findLeadByConversationId(string $conversationId): ?Lead
    {
        if ($conversationId === '') {
            return null;
        }

        /** @var ?Lead */
        return Lead::getByCustomField(CustomFieldEnum::CONVERSATION_ID->value, $conversationId);
    }

    protected function formatTranscript(array $transcript): array
    {
        return array_map(function (array $turn): array {
            return [
                'role' => $turn['role'] ?? 'unknown',
                'message' => $turn['message'] ?? '',
                'time_in_call_secs' => $turn['time_in_call_secs'] ?? 0,
                'tool_calls' => $turn['tool_calls'] ?? null,
                'tool_results' => $turn['tool_results'] ?? null,
                'source_medium' => $turn['source_medium'] ?? null,
            ];
        }, $transcript);
    }
}
