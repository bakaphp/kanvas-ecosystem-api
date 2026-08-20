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
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * The Engage usage leaderboard. A sibling of BuildAnalyticsAction rather than a mode of it: that
 * one is count-by-group over a single table, this needs a median plus lookups across five
 * connections (social, crm, event, intelligence, workflow, ecosystem).
 *
 * Attribution splits by direction. A rep-sent message and a human response time are credited to
 * `messages.users_id` — whoever actually typed it, which is the number the leaderboard is asked
 * for. AI sends, customer replies and appointments are credited to the lead owner instead, because
 * on those rows `users_id` is a system account (inbound carries the receiver webhook's user, AI
 * sends carry the agent's) and crediting it would pile the whole company onto one fake rep.
 *
 * Two connectors write rep-sent rows as a system user as well: WaSender stores `receiver->user` on
 * a message the rep typed on their own phone, and RespondIO does the same on every outgoing. So a
 * sender that belongs to a receiver, an agent, or the company's AI user falls back to the lead
 * owner rather than inventing a phantom top performer.
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

    private ?int $aiAgentUserId = null;
    private bool $aiAgentUserResolved = false;

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

        $counts = $this->messageCounts($typeIds);
        $pairs = $this->responsePairs($typeIds);

        $peopleIds = array_values(array_unique([
            ...array_column($counts, 'people_id'),
            ...array_column($pairs, 'people_id'),
        ]));

        $byRep = $this->foldByRep(
            $counts,
            $pairs,
            $this->ownersForPeople($peopleIds),
            $this->appointmentsByOwner(),
            $this->systemUserIds(),
        );

        $names = $this->resolveNames(array_keys($byRep));

        $rows = [];
        $allDeltas = [];
        foreach ($byRep as $repId => $bucket) {
            $rows[] = $this->presentRow($repId, $names[$repId] ?? 'user #' . $repId, $bucket);
            $allDeltas = [...$allDeltas, ...$bucket['deltas']];
        }

        usort($rows, fn (array $a, array $b): int => $b['total_sent'] <=> $a['total_sent']);

        return [
            'rows' => $rows,
            'team' => $this->teamRow($rows, $allDeltas),
        ];
    }

    /**
     * A row nobody can be credited for — a system-written send on a person whose lead has no
     * owner — is dropped rather than parked on a placeholder rep.
     *
     * @param  array<int, array{people_id: int, sender_type: string, users_id: int, count: int}>  $counts
     * @param  array<int, array{people_id: int, users_id: int, seconds: int}>  $pairs
     * @param  array<int, int>  $ownerByPeople
     * @param  array<int, int>  $appointments
     * @param  array<int, true>  $systemUsers
     * @return array<int, array<string, mixed>>
     */
    private function foldByRep(
        array $counts,
        array $pairs,
        array $ownerByPeople,
        array $appointments,
        array $systemUsers,
    ): array {
        $byRep = [];

        foreach ($counts as $row) {
            $metric = match ($row['sender_type']) {
                MessageSenderTypeEnum::USER->value => 'rep_sent',
                MessageSenderTypeEnum::AGENT->value => 'ai_sent',
                MessageSenderTypeEnum::CONTACT->value => 'replies',
                default => null,
            };

            if ($metric === null) {
                continue;
            }

            $repId = $metric === 'rep_sent'
                ? self::creditFor($row['users_id'], $row['people_id'], $ownerByPeople, $systemUsers)
                : $ownerByPeople[$row['people_id']] ?? null;

            if ($repId === null) {
                continue;
            }

            $byRep[$repId] ??= self::emptyBucket();
            $byRep[$repId][$metric] += $row['count'];
        }

        foreach ($pairs as $pair) {
            $repId = self::creditFor($pair['users_id'], $pair['people_id'], $ownerByPeople, $systemUsers);

            if ($repId === null) {
                continue;
            }

            $byRep[$repId] ??= self::emptyBucket();
            $byRep[$repId]['deltas'][] = $pair['seconds'];
        }

        foreach ($appointments as $ownerId => $count) {
            $byRep[$ownerId] ??= self::emptyBucket();
            $byRep[$ownerId]['appointments'] += $count;
        }

        // The AI has its own Kanvas user and would otherwise rank as a rep, double-counting volume
        // that every row already reports as ai_sent.
        $aiUserId = $this->aiAgentUserId();
        if ($aiUserId !== null) {
            unset($byRep[$aiUserId]);
        }

        return $byRep;
    }

    /**
     * Who gets the credit for a human-sent message. The sender is the honest answer, but two
     * connectors store a system account there (see the class docblock), so those rows fall back to
     * the lead owner — the pre-existing behaviour — instead of crowning the receiver as top rep.
     *
     * @param  array<int, int>  $ownerByPeople
     * @param  array<int, true>  $systemUsers
     */
    private static function creditFor(
        int $senderId,
        int $peopleId,
        array $ownerByPeople,
        array $systemUsers,
    ): ?int {
        if ($senderId > 0 && ! isset($systemUsers[$senderId])) {
            return $senderId;
        }

        return $ownerByPeople[$peopleId] ?? null;
    }

    /**
     * Accounts that are not reps: the company's AI agent user, the user each connector receiver
     * writes messages as, and every agent's own user. Not filtered by is_deleted — a retired
     * receiver's user is still not a person who sold anything.
     *
     * @return array<int, true>
     */
    private function systemUserIds(): array
    {
        $ids = [
            ...ReceiverWebhook::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->pluck('users_id')
                ->all(),
            ...Agent::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->pluck('user_id')
                ->all(),
        ];

        $aiUserId = $this->aiAgentUserId();
        if ($aiUserId !== null) {
            $ids[] = $aiUserId;
        }

        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /**
     * Throws when the configured id points at a deleted user; a dangling setting must not take the
     * whole report down. Worst case the AI keeps a row, which is visible and fixable, unlike a 500.
     *
     * Memoized because both the system-user set and the final row removal need it, and each miss
     * is a settings read plus a user lookup.
     */
    private function aiAgentUserId(): ?int
    {
        if ($this->aiAgentUserResolved) {
            return $this->aiAgentUserId;
        }

        $this->aiAgentUserResolved = true;

        try {
            $this->aiAgentUserId = $this->company->getAiAgentUser()?->getId();
        } catch (ModelNotFoundException | EloquentModelNotFoundException) {
            $this->aiAgentUserId = null;
        }

        return $this->aiAgentUserId;
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
     * Grouped by sender as well as person, so a rep-sent row can be credited to whoever typed it
     * while the rest of the row still resolves through the person's lead.
     *
     * @param  array<int, int>  $typeIds
     * @return array<int, array{people_id: int, sender_type: string, users_id: int, count: int}>
     */
    private function messageCounts(array $typeIds): array
    {
        $rows = $this->communicationMessages($typeIds)
            ->groupBy('people_id', 'sender_type', 'users_id')
            ->select([
                'people_id',
                'sender_type',
                'users_id',
                DB::raw('COUNT(*) as aggregate_count'),
            ])
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[] = [
                'people_id' => (int) $row->people_id,
                'sender_type' => (string) $row->sender_type,
                'users_id' => (int) $row->users_id,
                'count' => (int) $row->aggregate_count,
            ];
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
     * @return array<int, array{people_id: int, users_id: int, seconds: int}>
     */
    private function responsePairs(array $typeIds): array
    {
        $rows = $this->communicationMessages($typeIds)
            ->join('messages as reply', 'reply.id', '=', 'messages.response_message_id')
            ->where('messages.sender_type', MessageSenderTypeEnum::CONTACT->value)
            ->where('reply.sender_type', MessageSenderTypeEnum::USER->value)
            ->where('reply.is_deleted', 0)
            ->limit(self::MAX_RESPONSE_PAIRS)
            ->select([
                'messages.people_id',
                // The replier, not the inbound row's users_id — that one is the receiver webhook.
                'reply.users_id as reply_users_id',
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
                $pairs[] = [
                    'people_id' => (int) $row->people_id,
                    'users_id' => (int) $row->reply_users_id,
                    'seconds' => $seconds,
                ];
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
            ->get(['id', 'firstname', 'lastname', 'displayname', 'email'])
            ->mapWithKeys(function (Users $user): array {
                $realName = trim(trim((string) $user->firstname) . ' ' . trim((string) $user->lastname));

                return [
                    (int) $user->id => $realName !== ''
                        ? $realName
                        : (string) ($user->displayname ?: $user->email),
                ];
            })
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
