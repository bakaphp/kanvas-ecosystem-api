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
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use stdClass;

/**
 * Bulk side of the painting mirror: walks the paintings one studio uses and hands each to
 * SyncPaintingFromBrushCrazyAction, the same write path the webhook uses.
 *
 * Has to run before the calendarables of the same studio: an event synced while its painting has
 * no Theme yet falls back to Unassigned and stays there until the event itself changes.
 *
 * Soft-deleted paintings are read on purpose and imported flagged — past events still show them.
 */
class PullPaintingsFromBrushCrazyAction
{
    use MatchesStudioCalendarables;
    use PullsSourceInChunks;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected ?Carbon $since = null,
        protected int $chunkSize = 200,
    ) {
    }

    /**
     * A painting belongs to a studio when any lesson, event or workshop there — cancelled ones
     * included — was painted with it. Paintings no studio ever ran are not imported.
     *
     * Shared with the webhook so "does this studio use this painting" has one definition.
     */
    public static function paintingsOfStudio(Client $client, int $bcStudioId): Builder
    {
        return $client->table('paintings')
            ->select('paintings.*')
            ->where(
                fn (Builder $query) => self::orWhereStudioCalendarable(
                    $query,
                    'painting_id',
                    'paintings.id',
                    $bcStudioId
                )
            );
    }

    /**
     * @return array{synced: int, failed: int, errors: array<int, string>}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $sync = new SyncPaintingFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            new PaintingRelations($client),
        );
        $results = ['synced' => 0, 'failed' => 0, 'errors' => []];

        $query = self::paintingsOfStudio($client, $this->context->bcStudioId);
        $since = $this->sourceSince();

        if ($since !== null) {
            $this->applySince($query, $since);
        }

        $this->pullChunks(
            $query,
            'paintings.id',
            PaintingMapper::PAINTING_MORPH,
            fn (array $rows) => $sync->preload(array_map(fn (stdClass $row) => (int) $row->id, $rows)),
            function (stdClass $row) use ($sync): void {
                $sync->fromRow($row);
            },
            $results,
            $onProgress
        );

        return $results;
    }

    /**
     * A painting's sheet changes without its own row moving — a step or a photo edited, or a new
     * event in this studio starting to use it — so the window also looks at those. Tag and recipe
     * edits leave no timestamp a window can see (taggables has none, a detached pivot row is gone);
     * a full pass picks them up.
     */
    protected function applySince(Builder $query, string $since): void
    {
        $bcStudioId = $this->context->bcStudioId;

        $query->where(function (Builder $window) use ($since, $bcStudioId) {
            $window->where('paintings.updated_at', '>=', $since)
                ->orWhereExists(
                    fn (Builder $directions) => $directions->selectRaw('1')
                        ->from('painting_directions')
                        ->whereColumn('painting_directions.painting_id', 'paintings.id')
                        ->where('painting_directions.updated_at', '>=', $since)
                )
                ->orWhereExists(
                    fn (Builder $media) => $media->selectRaw('1')
                        ->from('media')
                        ->where('media.model_type', PaintingMapper::PAINTING_MORPH)
                        ->whereColumn('media.model_id', 'paintings.id')
                        ->where('media.updated_at', '>=', $since)
                )
                ->orWhereExists(
                    fn (Builder $media) => $media->selectRaw('1')
                        ->from('media')
                        ->join('painting_directions', 'painting_directions.id', '=', 'media.model_id')
                        ->where('media.model_type', PaintingMapper::DIRECTION_MORPH)
                        ->whereColumn('painting_directions.painting_id', 'paintings.id')
                        ->where('media.updated_at', '>=', $since)
                );

            self::orWhereStudioCalendarable(
                $window,
                'painting_id',
                'paintings.id',
                $bcStudioId,
                $since
            );
        });
    }
}
