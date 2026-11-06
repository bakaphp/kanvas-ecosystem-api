<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use RuntimeException;

/**
 * One rolling summary note per lead, the lead's `summary` note. Its `content` is the rendered text, so
 * everything that already reads a lead summary (get_past_opportunities) keeps working, and its
 * `lead_summary` key holds the state. Updated in place: content and state are one JSON column, one save.
 */
final class LeadSummaryRepository
{
    /** One lock for everything that writes a lead's summary: the update and the invalidation. */
    public static function lockKey(Lead $lead): string
    {
        return 'lead-summary:' . $lead->getId();
    }

    public static function note(Lead $lead): ?Message
    {
        $row = AppModuleMessage::query()
            ->forEntity($lead)
            ->where('apps_id', $lead->apps_id)
            ->whereHas('message', fn (Builder $query) => $query->where('is_deleted', 0))
            ->whereHas('message.messageType', fn (Builder $query) => $query->where('verb', LeadMessageTypeEnum::CONVERSATION_SUMMARY->value))
            ->with('message')
            ->orderByDesc('message_id')
            ->first();

        return $row?->message;
    }

    public static function state(Lead $lead): LeadSummaryState
    {
        $note = self::note($lead);

        return ($note === null ? null : LeadSummaryState::fromPayload($note->getMessage())) ?? new LeadSummaryState();
    }

    /**
     * Writes content and state in one save. A closed-lead summary written before rolling summaries is
     * taken over: its text stays the content until the first update replaces it.
     */
    public static function save(Lead $lead, LeadSummaryState $state): Message
    {
        $note = self::note($lead);
        $content = $state->render();

        if ($note === null) {
            $note = new RecordLeadNoteAction($lead)->execute(
                body: $content,
                tag: 'conversation-summary',
                isPublic: false,
                messageTypeVerb: LeadMessageTypeEnum::CONVERSATION_SUMMARY->value,
            ) ?? throw new RuntimeException("Could not write the summary note of lead {$lead->getId()}");
        }

        $payload = $note->getMessage();
        $payload['content'] = $content;
        $payload['raw_data'] = $content;
        $payload[LeadSummaryState::PAYLOAD_KEY] = $state->toPayload();

        $note->message = $payload;
        $note->saveOrFail();

        return $note;
    }
}
