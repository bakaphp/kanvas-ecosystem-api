<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Override;

class ProcessCallEndedAction extends BaseRespondIOAction
{
    use HasChannelProcessing;

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var array<string, mixed> $call */
        $call = $this->payload['call'] ?? [];
        /** @var array<string, mixed> $respondioUser */
        $respondioUser = $this->payload['user'] ?? [];
        /** @var string $eventId */
        $eventId = $this->payload['event_id'] ?? Str::uuid()->toString();

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $channel = $this->findChannelByIdentifier($this->app, $this->company, $identifier);

        if (! $channel) {
            return ['status' => 'no_channel_found', 'identifier' => $identifier];
        }

        $lead = $this->findLeadFromChannel($this->app, $this->company, $channel);

        $transcript = $this->formatTranscript($call['transcript'] ?? []);
        /** @var array<string, mixed> $callSummary */
        $callSummary = $call['callSummary'] ?? [];
        $summaryText = $this->formatCallSummary($callSummary);
        $durationSeconds = $call['durationSeconds'] ?? 0;
        $direction = $call['direction'] ?? 'unknown';
        $callStatus = $call['status'] ?? 'unknown';
        $recordingUrl = $call['recordingURL'] ?? null;

        $noteContent = "Call {$direction} ({$callStatus}) - Duration: {$durationSeconds}s";

        if ($summaryText !== '') {
            $noteContent .= "\n\n{$summaryText}";
        }

        if ($transcript !== '') {
            $noteContent .= "\n\nTranscript:\n{$transcript}";
        }

        if ($recordingUrl !== null) {
            $noteContent .= "\n\nRecording: {$recordingUrl}";
        }

        /** @var string $agentFirstName */
        $agentFirstName = $respondioUser['firstName'] ?? '';
        /** @var string $agentLastName */
        $agentLastName = $respondioUser['lastName'] ?? '';
        $agentName = trim($agentFirstName . ' ' . $agentLastName);
        if ($agentName !== '') {
            $noteContent .= "\n\nAgent: {$agentName}";
        }

        $notesChannel = $lead?->notes;

        if ($notesChannel === null && $lead !== null) {
            $notesChannel = new CreateChannelAction(
                new ChannelDto(
                    $this->app,
                    $this->company,
                    $this->user,
                    (string) $lead->getKey(),
                    get_class($lead),
                    ChannelNameEnum::NOTES->value,
                    'AI Notes Channel',
                    Str::uuid()->toString()
                )
            )->execute();
        }

        $messageSlug = 'respondio-call-' . Str::slug($eventId);

        $messageTypeModel = new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->app->getId(),
                0,
                'respondio-call',
                'respondio-call',
            )
        )->execute();

        $messageInput = new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            type: $messageTypeModel,
            message: [
                'content' => $noteContent,
                'raw_data' => $this->payload,
                'source' => 'respondio-call',
                'direction' => $direction,
                'duration_seconds' => $durationSeconds,
                'recording_url' => $recordingUrl,
                'call_status' => $callStatus,
            ],
            is_public: 0,
            slug: $messageSlug,
            tags: ['respondio', 'call']
        );

        $message = new CreateMessageAction($messageInput)->execute();

        if ($lead) {
            $message->addEntity($lead);
        }

        $targetChannel = $notesChannel ?? $channel;
        $targetChannel->addMessage($message);

        return [
            'status' => 'call_processed',
            'message_id' => $message->getId(),
            'channel_id' => $targetChannel->getId(),
            'lead_id' => $lead?->getId(),
            'direction' => $direction,
            'duration_seconds' => $durationSeconds,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $transcript
     */
    protected function formatTranscript(array $transcript): string
    {
        if (empty($transcript)) {
            return '';
        }

        $lines = [];
        foreach ($transcript as $entry) {
            $speaker = ucfirst((string) ($entry['speaker'] ?? 'unknown'));
            $text = $entry['text'] ?? '';
            $lines[] = "[{$speaker}]: {$text}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $callSummary
     */
    protected function formatCallSummary(array $callSummary): string
    {
        if (empty($callSummary)) {
            return '';
        }

        $parts = [];

        $title = $callSummary['title'] ?? null;
        if ($title !== null) {
            $parts[] = "Summary: {$title}";
        }

        /** @var array<int, string> $summaryPoints */
        $summaryPoints = $callSummary['summary'] ?? [];
        if (! empty($summaryPoints)) {
            $parts[] = implode("\n", array_map(fn (string $point) => "- {$point}", $summaryPoints));
        }

        /** @var array<int, string> $actionItems */
        $actionItems = $callSummary['actionItems'] ?? [];
        if (! empty($actionItems)) {
            $parts[] = "Action Items:\n" . implode("\n", array_map(fn (string $item) => "- {$item}", $actionItems));
        }

        return implode("\n\n", $parts);
    }
}
