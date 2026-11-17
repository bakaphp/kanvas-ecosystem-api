<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;

/**
 * Turns one BrushCrazy `paintings` row plus its related rows (directions, tags, recipe pivots,
 * media) into the values a Theme needs. Free of lookups so it stays unit-testable; the sync action
 * loads the related rows and writes.
 */
class PaintingMapper
{
    /** Morph aliases from BrushCrazy's enforceMorphMap — also what `media.model_type` stores. */
    public const string PAINTING_MORPH = 'painting';
    public const string DIRECTION_MORPH = 'painting-direction';

    /** `Painting::DESCRIPTION_SORT_ORDER`: the directions row holding the description, never a step. */
    public const int DESCRIPTION_SORT_ORDER = 4294967295;

    public const string PHOTO_FIELD = 'photo';

    public const string STAFF_PICK_TAG = 'staff-pick';

    /** `Painting::CURATION_TAG_PREFIXES`. */
    public const array TAG_PREFIXES = [
        'category' => 'category:',
        'technique' => 'technique:',
        'season' => 'season:',
        'audience' => 'audience:',
    ];

    /**
     * @param  array{directions?: array<int, object>, tags?: array<int, string>, recipe?: array<string, array<int, object>>, media?: array<int, object>}  $related
     * @return array{
     *     theme: array<string, mixed>,
     *     custom_fields: array<string, mixed>,
     *     files: array<string, array{media_id: int, url: string}>
     * }
     */
    public static function map(object $row, array $related, ?string $mediaBaseUrl): array
    {
        $photos = self::photos($related['media'] ?? [], $mediaBaseUrl);
        $directions = self::directions($related['directions'] ?? [], $photos);

        $files = [];

        if (isset($photos[self::PAINTING_MORPH . ':' . $row->id])) {
            $files[self::PHOTO_FIELD] = $photos[self::PAINTING_MORPH . ':' . $row->id];
        }

        foreach ($directions['steps'] as $step) {
            $photo = $photos[self::DIRECTION_MORPH . ':' . $step['id']] ?? null;

            if ($photo !== null) {
                $files[self::directionFieldName($step['id'])] = $photo;
            }
        }

        return [
            'theme' => [
                'name' => self::name($row),
                'is_deleted' => ($row->deleted_at ?? null) !== null,
                'created_at' => $row->created_at ?? null,
            ],
            // Nulls are kept on purpose: the sync deletes the field, so a value cleared at the
            // source is cleared in Kanvas too.
            'custom_fields' => [
                CustomFieldEnum::BRUSHCRAZY_PAINTING_ID->value => (int) $row->id,
                CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value => Str::trimToNull($row->subtitle ?? null),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_DIFFICULTY->value => Str::trimToNull($row->difficulty ?? null),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_DURATION->value => Str::trimToNull($row->duration ?? null),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_DESCRIPTION->value => $directions['description'],
                CustomFieldEnum::BRUSHCRAZY_PAINTING_DIRECTIONS->value => $directions['steps'] !== [] ? $directions['steps'] : null,
                CustomFieldEnum::BRUSHCRAZY_PAINTING_TAGS->value => self::groupTags($related['tags'] ?? []),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_RECIPE->value => self::recipe($related['recipe'] ?? []),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_PUBLISHED_AT->value => Str::trimToNull($row->published_at ?? null),
                CustomFieldEnum::BRUSHCRAZY_PAINTING_APPROVED_AT->value => Str::trimToNull($row->approved_at ?? null),
            ],
            'files' => $files,
        ];
    }

    /** Same string BrushCrazy shows everywhere (`Painting::getNameAttribute()`). */
    public static function name(object $row): string
    {
        return '#' . $row->id . ' - ' . trim((string) ($row->title ?? ''));
    }

    public static function directionFieldName(int $directionId): string
    {
        return 'direction-' . $directionId;
    }

    /**
     * @param  array<int, object>  $rows
     * @param  array<string, array{media_id: int, url: string}>  $photos
     * @return array{description: string|null, steps: array<int, array{id: int, sort_order: int, directions: string, photo_url: string|null}>}
     */
    public static function directions(array $rows, array $photos = []): array
    {
        $description = null;
        $steps = [];

        foreach ($rows as $row) {
            if ((int) $row->sort_order === self::DESCRIPTION_SORT_ORDER) {
                $description = Str::trimToNull($row->directions ?? null);

                continue;
            }

            $steps[] = [
                'id' => (int) $row->id,
                'sort_order' => (int) $row->sort_order,
                'directions' => (string) ($row->directions ?? ''),
                'photo_url' => $photos[self::DIRECTION_MORPH . ':' . $row->id]['url'] ?? null,
            ];
        }

        usort($steps, fn (array $a, array $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);

        return ['description' => $description, 'steps' => $steps];
    }

    /**
     * Lists rather than BrushCrazy's single value per prefix: the source reads the first matching
     * tag, but nothing stops a painting from carrying two, and dropping one here loses it for good.
     *
     * @param  array<int, string>  $names
     * @return array{category: array<int, string>, technique: array<int, string>, season: array<int, string>, audience: array<int, string>, staff_pick: bool, tags: array<int, string>}|null
     */
    public static function groupTags(array $names): ?array
    {
        $grouped = array_fill_keys(array_keys(self::TAG_PREFIXES), []) + ['staff_pick' => false, 'tags' => []];
        $any = false;

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $any = true;

            if ($name === self::STAFF_PICK_TAG) {
                $grouped['staff_pick'] = true;

                continue;
            }

            foreach (self::TAG_PREFIXES as $field => $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $value = trim(substr($name, strlen($prefix)));

                    if ($value !== '' && ! in_array($value, $grouped[$field], true)) {
                        $grouped[$field][] = $value;
                    }

                    continue 2;
                }
            }

            if (! in_array($name, $grouped['tags'], true)) {
                $grouped['tags'][] = $name;
            }
        }

        return $any ? $grouped : null;
    }

    /**
     * The pivots' FKs are ON DELETE SET NULL, so a deleted product leaves a row with a NULL id
     * behind; those carry nothing to show and are dropped.
     *
     * @param  array<string, array<int, object>>  $recipe
     * @return array<string, array<int, array<string, int|string>>>|null
     */
    public static function recipe(array $recipe): ?array
    {
        $shaped = [];

        foreach ($recipe as $kind => $items) {
            $list = [];

            foreach ($items as $item) {
                if (($item->id ?? null) === null || Str::trimToNull($item->name ?? null) === null) {
                    continue;
                }

                $list[] = array_filter(
                    [
                        'id' => (int) $item->id,
                        'name' => trim((string) $item->name),
                        'sort_order' => isset($item->sort_order) ? (int) $item->sort_order : null,
                        'location' => Str::trimToNull($item->location ?? null),
                    ],
                    fn ($value) => $value !== null
                );
            }

            if ($list !== []) {
                $shaped[$kind] = $list;
            }
        }

        return $shaped !== [] ? $shaped : null;
    }

    /**
     * Keyed "{model_type}:{model_id}". The `photo` collection is singleFile, but a replaced photo
     * can briefly coexist with its successor, so the newest media id wins.
     *
     * @param  array<int, object>  $media
     * @return array<string, array{media_id: int, url: string}>
     */
    public static function photos(array $media, ?string $mediaBaseUrl): array
    {
        if (Str::trimToNull($mediaBaseUrl) === null) {
            return [];
        }

        $photos = [];

        foreach ($media as $item) {
            $key = $item->model_type . ':' . $item->model_id;

            if (isset($photos[$key]) && $photos[$key]['media_id'] > (int) $item->id) {
                continue;
            }

            $photos[$key] = [
                'media_id' => (int) $item->id,
                'url' => self::mediaUrl((string) $mediaBaseUrl, $item),
            ];
        }

        return $photos;
    }

    /** Mirrors BrushCrazy's `App\Media\PathGenerator`: `{plural model_type}/{model_id}/{media_id}/{file_name}`. */
    public static function mediaUrl(string $mediaBaseUrl, object $media): string
    {
        return rtrim(trim($mediaBaseUrl), '/')
            . '/' . Str::plural((string) $media->model_type)
            . '/' . $media->model_id
            . '/' . $media->id
            . '/' . rawurlencode((string) $media->file_name);
    }
}
