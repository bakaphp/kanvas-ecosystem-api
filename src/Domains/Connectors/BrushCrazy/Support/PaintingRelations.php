<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Database\Query\Builder;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;

/**
 * Reads everything hanging off a set of paintings in one query per relation, so a chunk of paintings
 * costs a fixed number of round trips instead of nine per painting.
 *
 * Soft-deleted directions, tags and recipe targets are left out, as BrushCrazy's own relations do;
 * the painting itself is read with its deleted_at so the Theme can be flagged.
 */
class PaintingRelations
{
    /** kind => [pivot table, pivot fk, target table, extra pivot columns] */
    private const array RECIPE = [
        'substrates' => ['painting_substrates', 'product_id', 'products', ['sort_order']],
        'colors' => ['painting_colors', 'product_id', 'products', []],
        'brushes' => ['painting_brushes', 'product_id', 'products', []],
        'materials' => ['painting_materials', 'material_id', 'materials', []],
        'fonts' => ['painting_fonts', 'font_id', 'fonts', ['location']],
        'stencils' => ['painting_stencils', 'stencil_id', 'stencils', []],
    ];

    public function __construct(
        protected Client $client,
    ) {
    }

    /**
     * Every requested id gets an entry, related rows or not.
     *
     * @param  array<int, int>  $paintingIds
     * @return array<int, array{directions: array<int, object>, tags: array<int, string>, recipe: array<string, array<int, object>>, media: array<int, object>}>
     */
    public function forPaintings(array $paintingIds): array
    {
        $related = [];

        foreach ($paintingIds as $paintingId) {
            $related[(int) $paintingId] = ['directions' => [], 'tags' => [], 'recipe' => [], 'media' => []];
        }

        if ($related === []) {
            return [];
        }

        $ids = array_keys($related);
        $directionPainting = [];

        $directions = $this->client->table('painting_directions')
            ->select('id', 'painting_id', 'directions', 'sort_order')
            ->whereIn('painting_id', $ids)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($directions as $direction) {
            $related[(int) $direction->painting_id]['directions'][] = $direction;
            $directionPainting[(int) $direction->id] = (int) $direction->painting_id;
        }

        $tags = $this->client->table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', PaintingMapper::PAINTING_MORPH)
            ->whereIn('taggables.taggable_id', $ids)
            ->whereNull('tags.deleted_at')
            ->orderBy('tags.id')
            ->get(['taggables.taggable_id', 'tags.name']);

        foreach ($tags as $tag) {
            $related[(int) $tag->taggable_id]['tags'][] = (string) $tag->name;
        }

        foreach (self::RECIPE as $kind => [$pivot, $foreignKey, $target, $extra]) {
            $items = $this->recipeQuery(
                $pivot,
                $foreignKey,
                $target,
                $extra
            )->whereIn($pivot . '.painting_id', $ids)->get();

            foreach ($items as $item) {
                $related[(int) $item->painting_id]['recipe'][$kind][] = $item;
            }
        }

        $photos = self::mediaQuery($this->client, [
            PaintingMapper::PAINTING_MORPH => $ids,
            PaintingMapper::DIRECTION_MORPH => array_keys($directionPainting),
        ])->get();

        foreach ($photos as $media) {
            $paintingId = $media->model_type === PaintingMapper::PAINTING_MORPH
                ? (int) $media->model_id
                : $directionPainting[(int) $media->model_id];

            $related[$paintingId]['media'][] = $media;
        }

        return $related;
    }

    /**
     * Inner join on purpose: it drops the NULL-id orphans the pivots' ON DELETE SET NULL leaves.
     *
     * @param  array<int, string>  $extra
     */
    private function recipeQuery(
        string $pivot,
        string $foreignKey,
        string $target,
        array $extra
    ): Builder {
        $query = $this->client->table($pivot)
            ->join($target, $target . '.id', '=', $pivot . '.' . $foreignKey)
            ->whereNull($target . '.deleted_at')
            ->select([$pivot . '.painting_id', $target . '.id', $target . '.name']);

        foreach ($extra as $column) {
            $query->addSelect($pivot . '.' . $column);
        }

        if (in_array('sort_order', $extra, true)) {
            $query->orderBy($pivot . '.sort_order');
        }

        return $query->orderBy($target . '.id');
    }

    /**
     * The `photo` media of the given owners, oldest first — the order PaintingMapper::photos()
     * expects. The calendarable sync reads class photos through it too.
     *
     * @param  array<string, array<int, int>>  $idsByMorph  media model_type => model ids
     */
    public static function mediaQuery(Client $client, array $idsByMorph): Builder
    {
        $idsByMorph = array_filter($idsByMorph);

        return $client->table('media')
            ->select('id', 'model_type', 'model_id', 'file_name')
            ->where('collection_name', PaintingMapper::PHOTO_FIELD)
            ->where(function (Builder $query) use ($idsByMorph) {
                // No owners must match no media, not every photo in the table.
                if ($idsByMorph === []) {
                    $query->whereRaw('1 = 0');
                }

                foreach ($idsByMorph as $morph => $ids) {
                    $query->orWhere(
                        fn (Builder $owned) => $owned->where('model_type', $morph)->whereIn('model_id', $ids)
                    );
                }
            })
            ->orderBy('id');
    }
}
