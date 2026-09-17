<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;

class LeadConversationSummaryRepository
{
    /**
     * @param list<int> $leadIds
     *
     * @return array<int, Message> lead id => its most recent summary
     */
    public static function latestForLeads(AppInterface $app, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        return self::leadMessages($app->getId(), $leadIds)
            ->whereHas('messageType', self::summaryType(...))
            ->with('message')
            ->orderByDesc('message_id')
            ->get()
            ->unique('entity_id')
            ->filter(fn (AppModuleMessage $row): bool => $row->message !== null)
            ->mapWithKeys(fn (AppModuleMessage $row): array => [(int) $row->entity_id => $row->message])
            ->all();
    }

    /**
     * A summary is stale once the lead gets any message after it — e.g. the lead was reopened,
     * talked to again, and closed a second time.
     */
    public static function hasUpToDateSummary(Lead $lead): bool
    {
        $leadIds = [$lead->getId()];

        $latestSummaryId = self::leadMessages($lead->apps_id, $leadIds)
            ->whereHas('messageType', self::summaryType(...))
            ->max('message_id');

        if ($latestSummaryId === null) {
            return false;
        }

        $latestOtherId = self::leadMessages($lead->apps_id, $leadIds)
            ->whereDoesntHave('messageType', self::summaryType(...))
            ->max('message_id');

        return $latestOtherId === null || (int) $latestSummaryId > (int) $latestOtherId;
    }

    private static function summaryType(Builder $query): Builder
    {
        return $query->where('verb', LeadMessageTypeEnum::CONVERSATION_SUMMARY->value);
    }

    /**
     * @param list<int> $leadIds
     */
    private static function leadMessages(int $appId, array $leadIds): Builder
    {
        return AppModuleMessage::query()
            ->where('apps_id', $appId)
            ->where('system_modules', Lead::class)
            ->whereIn('entity_id', $leadIds)
            ->whereHas('message', fn (Builder $query) => $query->where('is_deleted', 0));
    }
}
