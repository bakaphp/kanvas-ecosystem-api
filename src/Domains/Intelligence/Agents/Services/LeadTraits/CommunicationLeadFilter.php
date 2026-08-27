<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services\LeadTraits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;

class CommunicationLeadFilter
{
    /** @return array{active: bool, criteria: array<string, mixed>, last_messages: array<int, array<string, mixed>>, matching_messages: int} */
    public function apply(Builder $query, Apps $app, Companies $company, array $filters): array
    {
        $state = strtolower(trim((string) ($filters['communication_state'] ?? '')));
        $waitingDays = $filters['customer_waiting_since_days'] ?? null;
        $active = $state !== '' || $waitingDays !== null;
        if (! $active) {
            return ['active' => false, 'criteria' => [], 'last_messages' => [], 'matching_messages' => 0];
        }
        if ($state === '' && $waitingDays !== null) {
            $state = 'awaiting_team_response';
        }
        if (! in_array($state, ['awaiting_team_response', 'responded', 'never_replied', 'no_messages'], true)) {
            throw new InvalidArgumentException(
                'Communication state must be awaiting_team_response, responded, never_replied, or no_messages.'
            );
        }
        if ($waitingDays !== null && (! is_int($waitingDays) || $waitingDays < 0)) {
            throw new InvalidArgumentException('customer_waiting_since_days must be zero or a positive integer.');
        }

        $latest = $this->latestMessages($app, $company)->keyBy('lead_id');
        $matching = match ($state) {
            'awaiting_team_response' => $latest->filter(
                fn (object $message): bool => $message->sender_type === MessageSenderTypeEnum::CONTACT->value
                    && ($waitingDays === null || Carbon::parse($message->created_at)->lte(now()->subDays($waitingDays))),
            ),
            'responded' => $latest->filter(fn (object $message): bool => in_array(
                $message->sender_type,
                [MessageSenderTypeEnum::USER->value, MessageSenderTypeEnum::AGENT->value],
                true,
            )),
            'never_replied' => $this->conversationSummaries($app, $company)
                ->filter(fn (object $summary): bool => ! (bool) $summary->has_contact && (bool) $summary->has_outbound)
                ->map(fn (object $summary): object => $latest->get((int) $summary->lead_id))
                ->filter(),
            'no_messages' => $latest,
        };

        $leadIds = $matching->pluck('lead_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $state === 'no_messages'
            ? $query->whereNotIn('id', $leadIds)
            : $query->whereIn('id', $leadIds === [] ? [-1] : $leadIds);

        return [
            'active' => true,
            'criteria' => [
                'state' => $state,
                'customer_waiting_since_days' => $waitingDays,
                'source_of_truth' => 'messages.sender_type',
            ],
            'last_messages' => $matching->mapWithKeys(fn (object $message): array => [
                (int) $message->lead_id => [
                    'message_id' => (int) $message->message_id,
                    'sender_type' => (string) $message->sender_type,
                    'created_at' => (string) $message->created_at,
                ],
            ])->all(),
            'matching_messages' => $matching->count(),
        ];
    }

    /** @param array<string, mixed> $context */
    public function attachMatches(array $rows, array $context): array
    {
        if (! $context['active']) {
            return $rows;
        }

        return array_map(static fn (array $row): array => [
            ...$row,
            'last_communication' => $context['last_messages'][(int) $row['lead_id']] ?? null,
        ], $rows);
    }

    protected function latestMessages(Apps $app, Companies $company): Collection
    {
        $latestTimes = $this->communicationQuery($app, $company)
            ->selectRaw('c.entity_id AS lead_id, MAX(m.created_at) AS last_created_at')
            ->groupBy('c.entity_id');
        $latestIds = $this->communicationQuery($app, $company)
            ->joinSub($latestTimes, 'latest_times', function ($join): void {
                $join->on('latest_times.lead_id', '=', 'c.entity_id')
                    ->on('latest_times.last_created_at', '=', 'm.created_at');
            })
            ->selectRaw('c.entity_id AS lead_id, MAX(m.id) AS message_id')
            ->groupBy('c.entity_id');

        return DB::connection('social')->table('messages as latest_message')
            ->joinSub($latestIds, 'latest', 'latest.message_id', '=', 'latest_message.id')
            ->select([
                'latest.lead_id',
                'latest_message.id as message_id',
                'latest_message.sender_type',
                'latest_message.created_at',
            ])
            ->get();
    }

    protected function conversationSummaries(Apps $app, Companies $company): Collection
    {
        return $this->communicationQuery($app, $company)
            ->selectRaw('c.entity_id AS lead_id')
            ->selectRaw('MAX(CASE WHEN m.sender_type = ? THEN 1 ELSE 0 END) AS has_contact', [MessageSenderTypeEnum::CONTACT->value])
            ->selectRaw('MAX(CASE WHEN m.sender_type IN (?, ?) THEN 1 ELSE 0 END) AS has_outbound', [
                MessageSenderTypeEnum::USER->value,
                MessageSenderTypeEnum::AGENT->value,
            ])
            ->groupBy('c.entity_id')
            ->get();
    }

    private function communicationQuery(Apps $app, Companies $company): QueryBuilder
    {
        return DB::connection('social')->table('messages as m')
            ->join('channel_messages as cm', 'cm.messages_id', '=', 'm.id')
            ->join('channels as c', 'c.id', '=', 'cm.channel_id')
            ->where('m.apps_id', $app->getId())
            ->where('m.companies_id', $company->getId())
            ->where('m.is_deleted', 0)
            ->whereNotNull('m.sender_type')
            ->where('c.apps_id', $app->getId())
            ->where('c.companies_id', $company->getId())
            ->where('c.is_deleted', 0)
            ->where('c.entity_namespace', Lead::class)
            ->whereNotNull('c.entity_id');
    }
}
