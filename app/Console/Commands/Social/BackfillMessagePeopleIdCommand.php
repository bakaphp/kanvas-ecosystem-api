<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Backfill messages.people_id from the entity each message is associated with in
 * app_module_message. New rows are populated live by CreateMessageAction::resolvePeopleId();
 * this catches up history so "who was talking" works on old conversations.
 *
 * Only real communication messages (sender_type IS NOT NULL) get a person — an internal note or
 * system row attached to a lead is not a message with a customer. Rows that already carry a
 * people_id but are not communication messages are cleared, so the command converges rather than
 * only ever adding.
 *
 * Cursors by message id rather than by "people_id IS NULL" — most messages (social posts,
 * ai-chat, system rows) legitimately have no person and would otherwise be reselected forever.
 * Safe to re-run and to resume with --from-id. Writes via the query builder, so no observers
 * fire and updated_at is left alone.
 *
 * app_module_message is on `social` and leads/deals/peoples on `crm`, so the entity ids are
 * resolved to people ids in a second query per chunk rather than joined.
 */
class BackfillMessagePeopleIdCommand extends Command
{
    protected $signature = 'kanvas:social:backfill-message-people-id
        {--chunk=2000 : Messages scanned per batch}
        {--app= : Restrict to a single apps_id}
        {--from-id=0 : Resume from this message id (exclusive)}
        {--dry-run : Resolve and report without writing}';

    protected $description = 'Backfill messages.people_id from the associated Lead / Deal / People entity.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;
        $dryRun = (bool) $this->option('dry-run');
        $lastId = (int) $this->option('from-id');

        $connection = DB::connection('social');

        $scanned = 0;
        $resolved = 0;
        $unresolved = 0;
        $cleared = 0;

        $this->info(sprintf(
            'Backfilling messages.people_id%s (chunk=%d, from-id=%d)%s',
            $appId !== null ? " for app {$appId}" : '',
            $chunk,
            $lastId,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        do {
            $rows = $connection->table('messages')
                ->select(['id', 'sender_type', 'people_id'])
                ->when($appId !== null, fn ($q) => $q->where('apps_id', $appId))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            // Only real communication messages carry a person. Anything else that already has one
            // (from an earlier, looser version of this backfill) gets cleared, so re-running the
            // command converges on the correct state instead of only ever adding.
            $messageIds = [];
            $staleIds = [];
            foreach ($rows as $row) {
                if ($row->sender_type !== null) {
                    $messageIds[] = (int) $row->id;
                } elseif ($row->people_id !== null) {
                    $staleIds[] = (int) $row->id;
                }
            }

            if ($staleIds !== []) {
                $cleared += $dryRun
                    ? count($staleIds)
                    : $connection->table('messages')->whereIn('id', $staleIds)->update(['people_id' => null]);
            }

            $peopleByMessage = $this->resolvePeopleForMessages($messageIds);

            if ($peopleByMessage !== []) {
                // Group by people_id so each distinct person is a single UPDATE ... WHERE IN
                // instead of one statement per message.
                $byPerson = [];
                foreach ($peopleByMessage as $messageId => $peopleId) {
                    $byPerson[$peopleId][] = $messageId;
                }

                foreach ($byPerson as $peopleId => $ids) {
                    // Count candidates rather than the UPDATE's return value: MySQL reports rows
                    // *changed*, so a re-run over already-correct data would report ~0 and read as
                    // a failed backfill. This also keeps --dry-run and the real run in agreement.
                    $resolved += count($ids);

                    if (! $dryRun) {
                        $connection->table('messages')
                            ->whereIn('id', $ids)
                            ->update(['people_id' => $peopleId]);
                    }
                }
            }

            $unresolved += count($messageIds) - count($peopleByMessage);
            $scanned += $rows->count();
            $lastId = (int) $rows->last()->id;

            $this->line(sprintf('  scanned %d (last id %d), resolved %d', $scanned, $lastId, $resolved));
        } while ($rows->count() === $chunk);

        $this->newLine();
        $this->info(sprintf(
            'Done. Scanned %d messages. %s %d, cleared %d non-communication row(s). No person in scope: %d.',
            $scanned,
            $dryRun ? 'Would set' : 'Set',
            $resolved,
            $cleared,
            $unresolved,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $messageIds
     * @return array<int, int> message id => people id
     */
    private function resolvePeopleForMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $links = DB::connection('social')
            ->table('app_module_message')
            ->whereIn('message_id', $messageIds)
            ->whereIn('system_modules', [People::class, Lead::class, Deal::class])
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get(['message_id', 'system_modules', 'entity_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $leadIds = [];
        $dealIds = [];
        foreach ($links as $link) {
            if ($link->system_modules === Lead::class) {
                $leadIds[] = (int) $link->entity_id;
            } elseif ($link->system_modules === Deal::class) {
                $dealIds[] = (int) $link->entity_id;
            }
        }

        $leadPeople = $this->peopleIdsFor(Lead::query(), $leadIds);
        $dealPeople = $this->peopleIdsFor(Deal::query(), $dealIds);

        $resolved = [];
        foreach ($links as $link) {
            $messageId = (int) $link->message_id;
            $entityId = (int) $link->entity_id;

            // A direct People link is the precise answer and overwrites; Lead/Deal only fill gaps.
            if ($link->system_modules === People::class) {
                $resolved[$messageId] = $entityId;

                continue;
            }

            $peopleId = $link->system_modules === Lead::class
                ? $leadPeople[$entityId] ?? null
                : $dealPeople[$entityId] ?? null;

            if ($peopleId !== null) {
                $resolved[$messageId] ??= $peopleId;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function peopleIdsFor(Builder $query, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $query
            ->whereIn('id', array_unique($ids))
            ->whereNotNull('people_id')
            ->pluck('people_id', 'id')
            ->map(fn ($peopleId): int => (int) $peopleId)
            ->all();
    }
}
