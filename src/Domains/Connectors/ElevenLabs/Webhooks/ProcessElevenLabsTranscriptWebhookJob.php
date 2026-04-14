<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
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
use Illuminate\Http\UploadedFile;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessElevenLabsTranscriptWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);

        return match ($type) {
            'post_call_transcription' => $this->handleTranscription($data),
            'post_call_audio' => $this->handleAudio($data),
            'call_initiation_failure' => $this->handleCallFailure($data),
            default => ['message' => 'Unknown webhook type: ' . $type],
        };
    }

    protected function handleTranscription(array $data): array
    {
        $conversationId = (string) ($data['conversation_id'] ?? '');
        $transcript = (array) ($data['transcript'] ?? []);
        $metadata = (array) ($data['metadata'] ?? []);
        $analysis = (array) ($data['analysis'] ?? []);
        $phoneCall = (array) ($metadata['phone_call'] ?? []);

        $phone = (string) ($phoneCall['from_number'] ?? $phoneCall['to_number'] ?? '');
        $lead = $this->findLeadByPhone($phone);

        if (! $lead) {
            return [
                'message' => 'No lead found for phone: ' . $phone,
                'conversation_id' => $conversationId,
            ];
        }

        $app = $this->receiver->app;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $app->getId(),
                name: 'ElevenLabs Voice Transcript',
                verb: 'elevenlabs-voice-transcript',
            )
        )->execute();

        $formattedTranscript = $this->formatTranscript($transcript);

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
                    'call_duration_secs' => $metadata['call_duration_secs'] ?? null,
                    'call_successful' => $analysis['call_successful'] ?? null,
                    'transcript_summary' => $analysis['transcript_summary'] ?? null,
                    'phone_from' => $phoneCall['from_number'] ?? null,
                    'phone_to' => $phoneCall['to_number'] ?? null,
                    'termination_reason' => $metadata['termination_reason'] ?? null,
                    'data_collection_results' => $analysis['data_collection_results'] ?? null,
                ],
                is_public: 1,
                slug: 'elevenlabs-' . $conversationId,
            ),
        )->execute();

        $message->addEntity($lead);

        return [
            'message' => 'Transcript saved',
            'conversation_id' => $conversationId,
            'message_id' => $message->getId(),
            'lead_id' => $lead->getId(),
        ];
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

        $existingMessage = Message::where('slug', 'elevenlabs-' . $conversationId)
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();

        if (! $existingMessage) {
            return [
                'message' => 'No transcript message found for conversation, audio skipped',
                'conversation_id' => $conversationId,
            ];
        }

        $lead = $existingMessage->leads()->first();

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
            new AttachFilesystemAction($file, $existingMessage)->execute('voice-recording');

            if ($lead) {
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
            'message_id' => $existingMessage->getId(),
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
            $lead->set('elevenlabs_call_failure', [
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

        $normalizedPhone = Str::normalizePhoneNumber($phone);
        $phoneWithCountryCode = str_replace('+', '', $phone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$normalizedPhone, $phoneWithCountryCode],
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

    protected function formatTranscript(array $transcript): array
    {
        return array_map(function (array $turn): array {
            return [
                'role' => $turn['role'] ?? 'unknown',
                'message' => $turn['message'] ?? '',
                'time_in_call_secs' => $turn['time_in_call_secs'] ?? 0,
                'tool_calls' => $turn['tool_calls'] ?? null,
                'tool_results' => $turn['tool_results'] ?? null,
            ];
        }, $transcript);
    }
}
