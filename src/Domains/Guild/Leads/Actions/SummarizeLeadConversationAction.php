<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadConversationSummaryRepository;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\Social\Messages\Models\Message;
use Laravel\Ai\Enums\Lab;

use function Laravel\Ai\agent;

class SummarizeLeadConversationAction
{
    private const string MODEL = 'gemini-2.5-flash';

    private const string INSTRUCTIONS = 'You summarize a finished sales conversation for the sales agent who will talk '
        . 'to this same customer again in the future. Write at most 6 short bullet points covering: what they were '
        . 'looking for (vehicle, product or service), budget or financing and trade-in details, objections or '
        . 'concerns, appointments or promises made, and how it ended (bought, went elsewhere, stopped answering, '
        . 'etc.). Use only facts stated in the conversation — never guess. Write in the language of the conversation. '
        . 'Return only the bullet points.';

    public function __construct(
        private readonly Lead $lead,
    ) {
    }

    public function execute(): ?Message
    {
        if (LeadConversationSummaryRepository::hasUpToDateSummary($this->lead)) {
            return null;
        }

        $lines = LeadConversationTranscriptService::lines($this->lead);

        if ($lines === []) {
            return null;
        }

        $status = $this->lead->status()->first()?->name ?? 'unknown';
        $transcript = "Lead: {$this->lead->title}\nFinal status: {$status}\n\nConversation:\n" . implode("\n", $lines);

        $response = agent(instructions: self::INSTRUCTIONS)->prompt(
            $transcript,
            provider: Lab::Gemini,
            model: self::MODEL,
        );

        $summary = Str::trimToNull($response->text);

        if ($summary === null) {
            return null;
        }

        return new RecordLeadNoteAction($this->lead)->execute(
            body: $summary,
            tag: 'conversation-summary',
            isPublic: false,
            messageTypeVerb: LeadMessageTypeEnum::CONVERSATION_SUMMARY->value,
        );
    }
}
