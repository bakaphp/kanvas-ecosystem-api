<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\MatchesStudioCalendarables;
use Kanvas\Connectors\BrushCrazy\Concerns\PullsSourceInChunks;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Facilitators\Models\Facilitator;
use stdClass;
use Throwable;

/**
 * Bulk side of the staff mirror: walks the users who work or worked in one studio and hands each
 * to SyncStaffFromBrushCrazyAction, the same write path the webhook uses.
 *
 * Must run before the calendarable pull: a class links its instructor through the Facilitator id
 * map, and a Facilitator that does not exist yet leaves the class without one until it re-syncs.
 */
class PullStaffFromBrushCrazyAction
{
    use MatchesStudioCalendarables;
    use PullsSourceInChunks;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected ?Carbon $since = null,
        protected int $chunkSize = 500,
    ) {
    }

    /**
     * Staff of a studio: anyone with an employment row there, current or past, its owners, and
     * anyone assigned to teach one of its classes — an instructor covering from another studio
     * still needs a Facilitator here for the class to link to.
     *
     * Soft-deleted employment rows are left out, as BrushCrazy's own queries leave them out;
     * soft-deleted users and calendarables are not, so past classes keep their instructor.
     *
     * Shared with the webhook so "is this user staff of this studio" has one definition.
     */
    public static function staffOfStudio(Client $client, int $bcStudioId): Builder
    {
        return $client->table('users')
            ->select('users.*')
            ->where(function (Builder $query) use ($bcStudioId) {
                $query->whereExists(
                    fn (Builder $employees) => $employees->selectRaw('1')
                        ->from('studio_employees')
                        ->whereColumn('studio_employees.user_id', 'users.id')
                        ->where('studio_employees.studio_id', $bcStudioId)
                        ->whereNull('studio_employees.deleted_at')
                )->orWhereExists(
                    fn (Builder $owners) => $owners->selectRaw('1')
                        ->from('studio_owners')
                        ->whereColumn('studio_owners.user_id', 'users.id')
                        ->where('studio_owners.studio_id', $bcStudioId)
                );

                self::orWhereStudioCalendarable(
                    $query,
                    'assignee_id',
                    'users.id',
                    $bcStudioId
                );
            });
    }

    /**
     * @param  array<int, int>  $bcUserIds
     * @return array<int, array<int, stdClass>> user id => their employment rows in this studio
     */
    public static function employmentsOf(Client $client, int $bcStudioId, array $bcUserIds): array
    {
        if ($bcUserIds === []) {
            return [];
        }

        return $client->table('studio_employees')
            ->where('studio_id', $bcStudioId)
            ->whereIn('user_id', $bcUserIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->all())
            ->all();
    }

    /**
     * @param  array<int, int>  $bcUserIds
     * @return array<int, true>
     */
    public static function ownersOf(Client $client, int $bcStudioId, array $bcUserIds): array
    {
        if ($bcUserIds === []) {
            return [];
        }

        return $client->table('studio_owners')
            ->where('studio_id', $bcStudioId)
            ->whereIn('user_id', $bcUserIds)
            ->pluck('user_id')
            ->mapWithKeys(fn ($userId) => [(int) $userId => true])
            ->all();
    }

    /**
     * @return array{synced: int, failed: int, errors: array<int, string>}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $sync = new SyncStaffFromBrushCrazyAction($this->app, $this->user, $this->context);
        $bcStudioId = $this->context->bcStudioId;
        $results = ['synced' => 0, 'failed' => 0, 'errors' => []];

        $query = self::staffOfStudio($client, $bcStudioId);

        if ($this->since !== null) {
            $this->applySince($query, $this->since->copy()->utc()->format('Y-m-d H:i:s'));
        }

        $visited = [];
        $employments = [];
        $owners = [];

        $this->pullChunks(
            $query,
            'users.id',
            'staff',
            function (array $rows) use ($client, $sync, $bcStudioId, &$visited, &$employments, &$owners) {
                $bcUserIds = array_map(fn (stdClass $row) => (int) $row->id, $rows);
                $visited += array_fill_keys($bcUserIds, true);
                $sync->preload($bcUserIds);
                $employments = self::employmentsOf($client, $bcStudioId, $bcUserIds);
                $owners = self::ownersOf($client, $bcStudioId, $bcUserIds);
            },
            function (stdClass $row) use ($sync, &$employments, &$owners) {
                return $sync->fromRow(
                    $row,
                    $employments[(int) $row->id] ?? [],
                    isset($owners[(int) $row->id])
                )->people;
            },
            $results,
            $onProgress
        );

        foreach ($this->departedCandidates($client, $visited) as $bcUserId) {
            try {
                $sync->fromUserId($client, $bcUserId);
            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = 'staff:' . $bcUserId . ' — ' . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Users whose Facilitator may need deactivating because they fell out of staffOfStudio()
     * without a row the walk above could see. A full run checks every Facilitator it did not
     * visit; a windowed run only those whose employment was soft-deleted inside the window, or
     * moved to another studio inside it (BrushCrazy rewrites `studio_id` in place, so the moved
     * row no longer matches this studio and the walk never sees it). `studio_employees` is small
     * enough that the window over every studio's rows stays cheap.
     *
     * @param  array<int, true>  $visited
     * @return array<int, int>
     */
    protected function departedCandidates(Client $client, array $visited): array
    {
        $idMaps = new IdMapRepository();

        if ($this->since === null) {
            $candidates = array_map('intval', array_keys($idMaps->load(
                $this->context->companyId(),
                Facilitator::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID
            )));
        } else {
            $since = $this->since->copy()->utc()->format('Y-m-d H:i:s');
            $userIds = fn (Builder $query) => $query->distinct()->pluck('user_id')->filter()->map(fn ($userId) => (int) $userId)->all();

            $movedAway = $userIds(
                $client->table('studio_employees')
                    ->where('studio_id', '<>', $this->context->bcStudioId)
                    ->where('updated_at', '>=', $since)
            );

            $candidates = array_values(array_unique([
                ...$userIds(
                    $client->table('studio_employees')
                        ->where('studio_id', $this->context->bcStudioId)
                        ->where('deleted_at', '>=', $since)
                ),
                ...array_map('intval', array_keys($idMaps->loadFor(
                    $this->context->companyId(),
                    Facilitator::class,
                    CustomFieldEnum::BRUSHCRAZY_USER_ID,
                    $movedAway
                ))),
            ]));
        }

        return array_values(array_filter($candidates, fn (int $bcUserId) => ! isset($visited[$bcUserId])));
    }

    /**
     * Joining, leaving or changing position only writes `studio_employees`, and a class
     * assignment only writes the calendarable, so the window looks at all three, not just `users`.
     */
    protected function applySince(Builder $query, string $since): void
    {
        $bcStudioId = $this->context->bcStudioId;

        $query->where(function (Builder $window) use ($since, $bcStudioId) {
            $window->where('users.updated_at', '>=', $since)
                ->orWhereExists(
                    fn (Builder $employees) => $employees->selectRaw('1')
                        ->from('studio_employees')
                        ->whereColumn('studio_employees.user_id', 'users.id')
                        ->where('studio_employees.studio_id', $bcStudioId)
                        ->where('studio_employees.updated_at', '>=', $since)
                )->orWhereExists(
                    fn (Builder $owners) => $owners->selectRaw('1')
                        ->from('studio_owners')
                        ->whereColumn('studio_owners.user_id', 'users.id')
                        ->where('studio_owners.studio_id', $bcStudioId)
                        ->where('studio_owners.updated_at', '>=', $since)
                );

            self::orWhereStudioCalendarable(
                $window,
                'assignee_id',
                'users.id',
                $bcStudioId,
                $since
            );
        });
    }
}
