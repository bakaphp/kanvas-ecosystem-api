<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Actions;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Users\Models\Users;

/**
 * The Engage usage leaderboard. A sibling of BuildAnalyticsAction rather than a mode of it: that
 * one is count-by-group over a single table, this needs a median plus lookups across four
 * connections (social, crm, event, ecosystem).
 *
 * Attribution runs `messages.people_id` -> the person's lead -> `leads_owner_id`, never
 * `messages.users_id`, which on inbound is the receiver webhook's user and would pile every
 * customer reply onto one system user.
 *
 * Counting off `people_id` instead of joining `app_module_message` is what keeps totals honest —
 * one message can carry 20+ association rows, and that join multiplied every metric.
 *
 * Rows predating kanvas:social:backfill-message-people-id have a NULL `people_id` and are invisible
 * here; re-run the backfill rather than adding a fallback join.
 */
class BuildEngagementLeaderboardAction
{
    private const int MAX_RESPONSE_PAIRS = 50000;
    private const int LOOKUP_CHUNK = 2000;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly AnalyticsRequest $request,
        protected readonly MessageChannelEnum $channel = MessageChannelEnum::ALL,
    ) {
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, team: array<string, mixed>}
     */
    public function execute(): array
    {
        $typeIds = MessagesTypesRepository::getCommunicationTypeIds($this->app, $this->channel);

        if ($typeIds === []) {
            return ['rows' => [], 'team' => $this->teamRow([], [])];
        }

        $counts = $this->messageCountsByPeople($typeIds);
        $pairs = $this->responsePairsByPeople($typeIds);

        $byOwner = $this->foldByOwner(
            $counts,
            $pairs,
            $this->ownersForPeople(array_unique([...array_keys($counts), ...array_keys($pairs)])),
            $this->appointmentsByOwner(),
        );

        $names = $this->resolveNames(array_keys($byOwner));

        $rows = [];
        $allDeltas = [];
        foreach ($byOwner as $ownerId => $bucket) {
            $rows[] = $this->presentRow($ownerId, $names[$ownerId] ?? 'user #' . $ownerId, $bucket);
            $allDeltas = [...$allDeltas, ...$bucket['deltas']];
        }

        usort($rows, fn (array $a, array $b): int => $b['total_sent'] <=> $a['total_sent']);

        return [
            'rows' => $rows,
            'team' => $this->teamRow($rows, $allDeltas),
        ];
    }

    /**
     * A person with no owning lead is dropped — there is no rep to credit.
     *
     * @param  array<int, array<string, int>>  $counts
     * @param  array<int, array<int, int>>  $pairs
     * @param  array<int, int>  $ownerByPeople
     * @param  array<int, int>  $appointments
     * @return array<int, array<string, mixed>>
     */
    private function foldByOwner(
        array $counts,
        array $pairs,
        array $ownerByPeople,
        array $appointments,
    ): array {
        $byOwner = [];

        foreach ($counts as $peopleId => $bySender) {
            if (! isset($ownerByPeople[$peopleId])) {
                continue;
            }

            $bucket = &$byOwner[$ownerByPeople[$peopleId]];
            $bucket ??= self::emptyBucket();
            $bucket['rep_sent'] += $bySender[MessageSenderTypeEnum::USER->value] ?? 0;
            $bucket['ai_sent'] += $bySender[MessageSenderTypeEnum::AGENT->value] ?? 0;
            $bucket['replies'] += $bySender[MessageSenderTypeEnum::CONTACT->value] ?? 0;
            unset($bucket);
        }

        foreach ($pairs as $peopleId => $deltas) {
            if (! isset($ownerByPeople[$peopleId])) {
                continue;
            }

            $bucket = &$byOwner[$ownerByPeople[$peopleId]];
            $bucket ??= self::emptyBucket();
            $bucket['deltas'] = [...$bucket['deltas'], ...$deltas];
            unset($bucket);
        }

        foreach ($appointments as $ownerId => $count) {
            $byOwner[$ownerId] ??= self::emptyBucket();
            $byOwner[$ownerId]['appointments'] += $count;
        }

        // The AI has its own Kanvas user and would otherwise rank as a rep, double-counting volume
        // that every row already reports as ai_sent.
        $aiUserId = $this->aiAgentUserId();
        if ($aiUserId !== null) {
            unset($byOwner[$aiUserId]);
        }

        return $byOwner;
    }

    /**
     * Throws when the configured id points at a deleted user; a dangling setting must not take the
     * whole report down. Worst case the AI keeps a row, which is visible and fixable, unlike a 500.
     */
    private function aiAgentUserId(): ?int
    {
        try {
            return $this->company->getAiAgentUser()?->getId();
        } catch (ModelNotFoundException | EloquentModelNotFoundException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyBucket(): array
    {
        return [
            'rep_sent' => 0,
            'ai_sent' => 0,
            'replies' => 0,
            'appointments' => 0,
            'deltas' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function presentRow(int $ownerId, string $name, array $bucket): array
    {
        $totalSent = $bucket['rep_sent'] + $bucket['ai_sent'];

        return [
            'users_id' => $ownerId,
            'name' => $name,
            'total_sent' => $totalSent,
            'ai_sent' => $bucket['ai_sent'],
            'rep_sent' => $bucket['rep_sent'],
            'replies' => $bucket['replies'],
            'reply_rate' => self::ratio($bucket['replies'], $totalSent),
            'median_response_seconds' => self::median($bucket['deltas']),
            'appointments' => $bucket['appointments'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $allDeltas
     * @return array<string, mixed>
     */
    private function teamRow(array $rows, array $allDeltas): array
    {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $totalSent = $sum('total_sent');

        return [
            'total_sent' => $totalSent,
            'ai_sent' => $sum('ai_sent'),
            'rep_sent' => $sum('rep_sent'),
            'replies' => $sum('replies'),
            'reply_rate' => self::ratio($sum('replies'), $totalSent),
            // Median over every pair, not a mean of per-rep medians — the latter is not a median.
            'median_response_seconds' => self::median($allDeltas),
            'appointments' => $sum('appointments'),
            'ai_share' => self::ratio($sum('ai_sent'), $totalSent),
            'reps' => count($rows),
        ];
    }

    /**
     * @param  array<int, int>  $typeIds
     * @return array<int, array<string, int>>
     */
    private function messageCountsByPeople(array $typeIds): array
    {
        $rows = $this->communicationMessages($typeIds)
            ->groupBy('people_id', 'sender_type')
            ->select([
                'people_id',
                'sender_type',
                DB::raw('COUNT(*) as aggregate_count'),
            ])
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->people_id][(string) $row->sender_type] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    /**
     * Human replies only — the AI's sub-second answers would make a "rep resp time" meaningless.
     *
     * Known skew: MarkLeadMessagesAsRespondedAction marks *every* unresponded message on the lead
     * against a single reply, so three texts answered once produce three pairs. Median absorbs that
     * far better than a mean.
     *
     * @param  array<int, int>  $typeIds
     * @return array<int, array<int, int>>
     */
    private function responsePairsByPeople(array $typeIds): array
    {
        $rows = $this->communicationMessages($typeIds)
            ->join('messages as reply', 'reply.id', '=', 'messages.response_message_id')
            ->where('messages.sender_type', MessageSenderTypeEnum::CONTACT->value)
            ->where('reply.sender_type', MessageSenderTypeEnum::USER->value)
            ->where('reply.is_deleted', 0)
            ->limit(self::MAX_RESPONSE_PAIRS)
            ->select([
                'messages.people_id',
                DB::raw('TIMESTAMPDIFF(SECOND, messages.created_at, reply.created_at) as response_seconds'),
            ])
            ->get();

        if ($rows->count() === self::MAX_RESPONSE_PAIRS) {
            Log::warning('Engagement leaderboard: response-pair cap reached, median is computed on a truncated set', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
                'cap' => self::MAX_RESPONSE_PAIRS,
            ]);
        }

        $pairs = [];
        foreach ($rows as $row) {
            $seconds = (int) $row->response_seconds;

            // A reply stamped before its inbound is clock skew or a backfill artifact.
            if ($seconds >= 0) {
                $pairs[(int) $row->people_id][] = $seconds;
            }
        }

        return $pairs;
    }

    /**
     * Table-qualified throughout so the response-pair self-join can build on the same base.
     *
     * @param  array<int, int>  $typeIds
     */
    private function communicationMessages(array $typeIds): Builder
    {
        return Message::query()
            ->where('messages.apps_id', $this->app->getId())
            ->where('messages.companies_id', $this->company->getId())
            ->where('messages.is_deleted', 0)
            ->whereBetween('messages.created_at', $this->range())
            ->whereIn('messages.message_types_id', $typeIds)
            ->whereNotNull('messages.sender_type')
            ->whereNotNull('messages.people_id');
    }

    /**
     * CalendarEventTool writes `events.users_id` = the lead owner, already the leaderboard's key.
     *
     * @return array<int, int>
     */
    private function appointmentsByOwner(): array
    {
        $rows = Event::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->whereBetween('created_at', $this->range())
            ->where('resources_type', Lead::class)
            ->whereNotNull('users_id')
            ->groupBy('users_id')
            ->select(['users_id', DB::raw('COUNT(*) as aggregate_count')])
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->users_id] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    /**
     * `peoples.users_id` is NOT the rep — it is whoever created the row, one system user for every
     * imported contact (~90k peoples in production). Ownership only exists on the lead.
     *
     * Several leads for one person credits the most recent; ascending order lets later rows win.
     * Messages are on `social` and leads on `crm`, so this is a lookup, not a join.
     *
     * @param  array<int, int>  $peopleIds
     * @return array<int, int> people id => owner user id
     */
    private function ownersForPeople(array $peopleIds): array
    {
        $owners = [];
        foreach (array_chunk($peopleIds, self::LOOKUP_CHUNK) as $chunk) {
            $rows = Lead::query()
                ->whereIn('people_id', $chunk)
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('is_deleted', 0)
                ->whereNotNull('leads_owner_id')
                ->orderBy('id')
                ->get(['people_id', 'leads_owner_id']);

            foreach ($rows as $row) {
                $owners[(int) $row->people_id] = (int) $row->leads_owner_id;
            }
        }

        return $owners;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    private function resolveNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Users::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'displayname', 'email'])
            ->mapWithKeys(fn (Users $user): array => [
                (int) $user->id => (string) ($user->displayname ?: $user->email),
            ])
            ->all();
    }

    /**
     * @return array<int, Carbon>
     */
    private function range(): array
    {
        return [
            $this->request->from->copy()->utc(),
            $this->request->to->copy()->utc(),
        ];
    }

    private static function ratio(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }

    /**
     * @param  array<int, int>  $values
     */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
