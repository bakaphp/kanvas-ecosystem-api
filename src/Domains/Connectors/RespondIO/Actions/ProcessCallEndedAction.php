<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Override;

class ProcessCallEndedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $call */
        $call = $this->payload['call'] ?? [];
        /** @var array<string, mixed> $respondioUser */
        $respondioUser = $this->payload['user'] ?? [];
        /** @var string $eventId */
        $eventId = $this->payload['event_id'] ?? Str::uuid()->toString();

        $channel = $this->resolveChannel();

        if ($channel === null) {
            return $this->unresolved('no_channel_found');
        }

        $lead = $this->resolveLead($channel);

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

        $messageInput = new MessageInput(
            app: $this->receiver->app,
            company: $this->receiver->company,
            user: $this->receiver->user,
            type: MessageTypeService::getOrCreate($this->receiver->app, 'respondio-call'),
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
            slug: 'respondio-call-' . Str::slug($eventId),
            tags: ['respondio', 'call']
        );

        $message = new CreateMessageAction($messageInput)->execute();

        if ($lead !== null) {
            $message->addEntity($lead);
        }

        // A call on a conversation with no lead is kept on the conversation itself.
        $targetChannel = $lead !== null ? $this->notesChannelOf($lead) : $channel;
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
