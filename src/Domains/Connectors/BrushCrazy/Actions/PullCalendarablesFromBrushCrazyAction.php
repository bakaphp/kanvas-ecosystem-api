<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\PullsSourceInChunks;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\EventVersion;
use Throwable;

/**
 * Bulk side of the calendarable mirror: walks lessons, events and workshops for one studio and
 * hands each row to SyncCalendarableFromBrushCrazyAction, the same write path the webhook uses.
 *
 * Soft-deleted rows are read on purpose — a refund or a cancellation is a soft delete at the
 * source, and filtering them out would leave the Kanvas copy live forever.
 */
class PullCalendarablesFromBrushCrazyAction
{
    use PullsSourceInChunks;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected Carbon $correctionCutoff,
        protected ?Carbon $since = null,
        protected int $chunkSize = 500,
    ) {
    }

    /**
     * @return array{synced: int, failed: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $sync = new SyncCalendarableFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            $this->correctionCutoff,
            client: $client,
        );
        $results = ['synced' => 0, 'failed' => 0, 'errors' => [], 'warnings' => []];
        $visited = [];

        foreach (CalendarableTypeEnum::cases() as $morph) {
            $query = $client->table($morph->table())->where('studio_id', $this->context->bcStudioId);

            if ($this->since !== null) {
                $this->applySince(
                    $query,
                    $morph,
                    $this->sourceSince()
                );
            }

            $query->chunkById(
                $this->chunkSize,
                function ($rows) use ($client, $sync, $morph, &$results, &$visited, $onProgress) {
                    SyncCalendarableFromBrushCrazyAction::attachSubstrates($client, $morph, $rows->all());
                    $sync->preloadPhotos($morph, $rows->map(fn ($row) => (int) $row->id)->all());

                    foreach ($rows as $row) {
                        $visited[$morph->key($row->id)] = true;

                        try {
                            $sync->fromRow($morph, $row);
                            $results['synced']++;
                        } catch (Throwable $e) {
                            $results['failed']++;
                            $results['errors'][] = $morph->key($row->id) . ' — ' . $e->getMessage();
                        }
                    }

                    if ($onProgress !== null) {
                        $onProgress($morph, $rows->count());
                    }
                }
            );
        }

        if ($this->since === null) {
            $this->retireMovedAway($client, $visited, $results);
        }

        return $results;
    }

    /**
     * A class moved to another studio is only announced to its new studio by the incremental
     * pull, so a full pass checks the mapped classes its walk no longer reached. Full passes only:
     * the source has no `updated_at` index, so a window over every studio's rows is a table scan.
     *
     * @param  array<string, true>  $visited
     * @param  array<string, mixed>  $results
     */
    protected function retireMovedAway(Client $client, array $visited, array &$results): void
    {
        $unvisited = array_values(array_filter(
            array_keys(new IdMapRepository()->load(
                $this->context->companyId(),
                EventVersion::class,
                CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY
            )),
            fn ($key) => ! isset($visited[(string) $key])
        ));

        $sweep = new RetireMovedBrushCrazyEntityAction($this->app, $this->context)
            ->sweepCalendarables($client, array_map('strval', $unvisited));

        $results['failed'] += $sweep['failed'];
        array_push($results['errors'], ...$sweep['errors']);
        array_push($results['warnings'], ...$sweep['warnings']);

        if ($sweep['moved'] > 0) {
            $results['warnings'][] = "{$sweep['moved']} classes moved to another studio; retired here";
        }
    }

    /**
     * Editing a ticket option or the class photo leaves the class's own `updated_at` alone, so the
     * window also looks at those rows. A removed photo is a hard delete and leaves no timestamp; a
     * full pass picks it up.
     */
    protected function applySince(Builder $query, CalendarableTypeEnum $morph, string $since): void
    {
        $table = $morph->table();

        $query->where(function (Builder $window) use ($table, $morph, $since) {
            $window->where($table . '.updated_at', '>=', $since)
                ->orWhereExists(
                    fn (Builder $substrates) => $substrates->selectRaw('1')
                        ->from('calendarable_substrates')
                        ->where('calendarable_substrates.calendarable_type', $morph->value)
                        ->whereColumn('calendarable_substrates.calendarable_id', $table . '.id')
                        ->where('calendarable_substrates.updated_at', '>=', $since)
                )
                ->orWhereExists(
                    fn (Builder $media) => $media->selectRaw('1')
                        ->from('media')
                        ->where('media.model_type', $morph->value)
                        ->whereColumn('media.model_id', $table . '.id')
                        ->where('media.collection_name', PaintingMapper::PHOTO_FIELD)
                        ->where('media.updated_at', '>=', $since)
                );
        });
    }
}
