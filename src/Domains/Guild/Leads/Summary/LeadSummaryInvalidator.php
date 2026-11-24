<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Illuminate\Support\Facades\Cache;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * The coverage marker only knows new ids. A summarized message that changes underneath it (its text
 * edited, deleted, or an older message attached to the lead late by a CRM sync or a merge) restarts
 * coverage from that id. Only the text counts: connectors rewrite the payload constantly (delivery
 * statuses, CRM push ids), and none of that changes what the conversation said.
 */
final class LeadSummaryInvalidator
{
    private const int LOCK_SECONDS = 30;

    public static function messageUpdated(Message $message): void
    {
        $deleted = $message->wasChanged('is_deleted') && (int) $message->is_deleted === 1;

        if ($deleted || self::textChanged($message)) {
            self::invalidate($message);
        }
    }

    private static function textChanged(Message $message): bool
    {
        return $message->wasChanged('message')
            && self::textOf($message->getRawOriginal('message')) !== self::textOf($message->getAttributes()['message'] ?? null);
    }

    public static function messageDeleted(Message $message): void
    {
        self::invalidate($message);
    }

    public static function messageAttached(AppModuleMessage $row): void
    {
        if ($row->system_modules !== Lead::class) {
            return;
        }

        $message = $row->message;
        $lead = self::lead((int) $row->entity_id, $row->apps_id);

        if ($lead !== null && $message !== null && LeadConversationMessages::isConversationKind($message)) {
            self::invalidateLead($lead, (int) $row->message_id);
        }
    }

    /**
     * The summary note itself is never conversation, and it is saved under the summary lock: letting it
     * through would wait on the lock its own save holds.
     */
    private static function invalidate(Message $message): void
    {
        if (! LeadConversationMessages::isConversationKind($message)) {
            return;
        }

        $leadIds = AppModuleMessage::query()
            ->where('message_id', $message->getId())
            ->where('system_modules', Lead::class)
            ->pluck('entity_id');

        foreach ($leadIds as $leadId) {
            $lead = self::lead((int) $leadId, $message->apps_id);

            if ($lead !== null) {
                self::invalidateLead($lead, (int) $message->getId());
            }
        }
    }

    /**
     * Pinned to the message's app: an attach row or a message id is never trusted to name a lead of
     * another tenant. Deleted leads are included; their summary is simply never read again.
     */
    private static function lead(int $leadId, int $appsId): ?Lead
    {
        return Lead::query()->whereKey($leadId)->where('apps_id', $appsId)->first();
    }

    /**
     * Never fails the write that triggered it: the next catch-up still covers new ids, only this rebuild
     * is lost, and it is reported.
     */
    private static function invalidateLead(Lead $lead, int $messageId): void
    {
        // Almost every attach is a new message above the marker; settle that without the lock.
        if (
            ! LeadSummaryRepository::enabledFor($lead->app)
            || $messageId > LeadSummaryRepository::state($lead)->through
        ) {
            return;
        }

        try {
            Cache::lock(LeadSummaryRepository::lockKey($lead), self::LOCK_SECONDS)->block(
                self::LOCK_SECONDS,
                function () use ($lead, $messageId): void {
                    $note = LeadSummaryRepository::note($lead);
                    $state = $note === null ? null : LeadSummaryState::fromPayload($note->getMessage());

                    if ($state === null || $messageId > $state->through) {
                        return;
                    }

                    LeadSummaryRepository::save($lead, $state->withChangedMessage($messageId, now()->toDateString()));
                }
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function textOf(mixed $raw): string
    {
        $snapshot = new Message();
        $snapshot->setRawAttributes(['message' => $raw], sync: true);

        return trim($snapshot->contentText());
    }
}
