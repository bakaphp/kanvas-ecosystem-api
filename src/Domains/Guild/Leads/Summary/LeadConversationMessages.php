<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;

/**
 * Which of a lead's messages are its conversation, the one predicate the freshness check, the summary
 * input and the token estimate all use. Customer, salesperson, agent and staff messages on every
 * channel count, AI Assist and notes included: staff instructions live there. Generated summaries,
 * compaction notes and tool telemetry do not, or the summary note would sit above its own marker and
 * the lead would never read as current.
 */
final class LeadConversationMessages
{
    private const array NOT_CONVERSATION_VERBS = [
        LeadMessageTypeEnum::CONVERSATION_SUMMARY->value,
        AgentMessageTypeEnum::AGENT_SUMMARY->value,
    ];

    /** KanvasTokenCounter's ratio, so a chunk budget and the history budget mean the same thing. */
    private const int CHARS_PER_TOKEN = 4;

    public static function counts(Message $message): bool
    {
        return (int) $message->is_deleted !== 1
            && self::isConversationKind($message)
            && trim($message->contentText()) !== '';
    }

    /**
     * The kind of row that can be conversation, whatever its state now: a deleted or emptied message
     * was conversation, a summary note never was.
     */
    public static function isConversationKind(Message $message): bool
    {
        if (in_array($message->messageType?->verb, self::NOT_CONVERSATION_VERBS, true)) {
            return false;
        }

        $payload = $message->getMessage();

        return ! isset($payload['tool_calls']) && ! isset($payload['tool_results']);
    }

    /**
     * The counted messages after `$afterId`, oldest first, as many as fit `$tokenBudget` (always at
     * least one, or a single long message would stall coverage forever).
     *
     * @return list<Message>
     */
    public static function after(Lead $lead, int $afterId, int $tokenBudget): array
    {
        $batch = [];
        $spent = 0;

        foreach (self::countedAfter($lead, $afterId) as $message) {
            $tokens = self::tokens($message);

            if ($batch !== [] && $spent + $tokens > $tokenBudget) {
                break;
            }

            $batch[] = $message;
            $spent += $tokens;
        }

        return $batch;
    }

    /**
     * Token estimate of the counted messages after `$afterId`, stopping as soon as `$cap` is passed:
     * the caller only needs to know whether it is over a threshold.
     */
    public static function tokensAfter(Lead $lead, int $afterId, int $cap): int
    {
        $spent = 0;

        foreach (self::countedAfter($lead, $afterId) as $message) {
            $spent += self::tokens($message);

            if ($spent > $cap) {
                break;
            }
        }

        return $spent;
    }

    /**
     * @return Generator<int, Message>
     */
    private static function countedAfter(Lead $lead, int $afterId): Generator
    {
        foreach (self::query($lead)->where('message_id', '>', $afterId)->lazyById(200, 'message_id') as $row) {
            if ($row->message !== null && self::counts($row->message)) {
                yield $row->message;
            }
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int> the ids that are this lead's counted messages
     */
    public static function countedAmong(Lead $lead, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return self::query($lead)
            ->whereIn('message_id', $ids)
            ->get()
            ->filter(static fn (AppModuleMessage $row): bool => $row->message !== null && self::counts($row->message))
            ->map(static fn (AppModuleMessage $row): int => (int) $row->message_id)
            ->values()
            ->all();
    }

    public static function line(Message $message): string
    {
        return LeadSummaryBatch::line(
            (int) $message->getId(),
            $message->created_at?->format('Y-m-d H:i') ?? 'unknown date',
            self::speaker($message),
            $message->contentText(),
        );
    }

    public static function speaker(Message $message): string
    {
        $payload = $message->getMessage();
        $channel = $message->channels->first();

        if ($channel?->isNoteChannel() || $channel?->isAiAssistChannel()) {
            return 'Staff note (internal)';
        }

        if ((bool) ($payload['from_ia'] ?? false)) {
            return 'Agent';
        }

        if ((bool) ($payload['from_human'] ?? false)) {
            return 'Salesperson' . ($message->user?->displayname ? ' ' . $message->user->displayname : '');
        }

        return 'Customer';
    }

    public static function tokens(Message $message): int
    {
        return self::estimateTokens($message->contentText());
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    private static function query(Lead $lead): Builder
    {
        return AppModuleMessage::query()
            ->forEntity($lead)
            ->where('apps_id', $lead->apps_id)
            ->with(['message.messageType', 'message.channels', 'message.user']);
    }
}
