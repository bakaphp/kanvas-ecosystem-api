<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Tests\TestCase;

final class PaintingMapperTest extends TestCase
{
    private const string BASE_URL = 'https://media.example.com/';

    public function testNameMatchesWhatBrushCrazyDisplays(): void
    {
        $mapped = PaintingMapper::map($this->row(), [], null);

        $this->assertSame('#42 - Starry Night', $mapped['theme']['name']);
        $this->assertFalse($mapped['theme']['is_deleted']);
        $this->assertSame('2021-03-01 10:00:00', $mapped['theme']['created_at']);
    }

    public function testSoftDeletedPaintingIsFlaggedNotDropped(): void
    {
        $this->assertTrue(PaintingMapper::map($this->row(['deleted_at' => '2024-01-01 00:00:00']), [], null)['theme']['is_deleted']);
    }

    public function testDescriptionRowIsTheDescriptionAndNeverAStep(): void
    {
        $fields = PaintingMapper::map($this->row(), ['directions' => $this->directions()], null)['custom_fields'];
        $steps = $fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_DIRECTIONS->value];

        $this->assertSame('A night sky.', $fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_DESCRIPTION->value]);
        $this->assertSame([10, 11], array_column($steps, 'id'));
        $this->assertSame(['Base coat', 'Stars'], array_column($steps, 'directions'));
    }

    public function testClearedValuesStayAsNullSoTheSyncCanDeleteThem(): void
    {
        $fields = PaintingMapper::map($this->row(['subtitle' => '  ']), [], null)['custom_fields'];

        $this->assertArrayHasKey(CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value, $fields);
        $this->assertNull($fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_SUBTITLE->value]);
        $this->assertNull($fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_DIRECTIONS->value]);
        $this->assertNull($fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_TAGS->value]);
        $this->assertSame(42, $fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_ID->value]);
        $this->assertSame('Beginner', $fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_DIFFICULTY->value]);
        $this->assertSame('2021-03-02 09:00:00', $fields[CustomFieldEnum::BRUSHCRAZY_PAINTING_PUBLISHED_AT->value]);
    }

    public function testTagsAreGroupedByCurationPrefix(): void
    {
        $tags = PaintingMapper::groupTags([
            'category:landscape',
            'technique:sponge',
            'season:winter',
            'audience:kids',
            'staff-pick',
            'night',
            'night',
            ' ',
        ]);

        $this->assertSame(['landscape'], $tags['category']);
        $this->assertSame(['sponge'], $tags['technique']);
        $this->assertSame(['winter'], $tags['season']);
        $this->assertSame(['kids'], $tags['audience']);
        $this->assertTrue($tags['staff_pick']);
        $this->assertSame(['night'], $tags['tags']);
    }

    public function testNoTagsIsNull(): void
    {
        $this->assertNull(PaintingMapper::groupTags([]));
    }

    public function testRecipeSkipsOrphanPivotRows(): void
    {
        $recipe = PaintingMapper::recipe([
            'substrates' => [
                (object) ['id' => 5, 'name' => '11x14 Canvas', 'sort_order' => 1],
                (object) ['id' => null, 'name' => null, 'sort_order' => 2],
            ],
            'fonts' => [(object) ['id' => 7, 'name' => 'Lobster', 'location' => 'Bottom left']],
            'colors' => [(object) ['id' => null, 'name' => null]],
        ]);

        $this->assertSame([['id' => 5, 'name' => '11x14 Canvas', 'sort_order' => 1]], $recipe['substrates']);
        $this->assertSame([['id' => 7, 'name' => 'Lobster', 'location' => 'Bottom left']], $recipe['fonts']);
        $this->assertArrayNotHasKey('colors', $recipe);
        $this->assertNull(PaintingMapper::recipe(['colors' => [(object) ['id' => null, 'name' => null]]]));
    }

    public function testMediaUrlFollowsTheSourcePathGenerator(): void
    {
        $url = PaintingMapper::mediaUrl(self::BASE_URL, (object) [
            'id' => 900,
            'model_type' => 'painting-direction',
            'model_id' => 10,
            'file_name' => 'step 1.jpg',
        ]);

        $this->assertSame('https://media.example.com/painting-directions/10/900/step%201.jpg', $url);
    }

    public function testPhotosBecomeFilesKeyedByFieldAndNewestMediaWins(): void
    {
        $mapped = PaintingMapper::map(
            $this->row(),
            ['directions' => $this->directions(), 'media' => $this->media()],
            self::BASE_URL
        );

        $this->assertSame(
            [
                PaintingMapper::PHOTO_FIELD => ['media_id' => 501, 'url' => 'https://media.example.com/paintings/42/501/new.jpg'],
                'direction-10' => ['media_id' => 600, 'url' => 'https://media.example.com/painting-directions/10/600/base.jpg'],
            ],
            $mapped['files']
        );

        $steps = $mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_PAINTING_DIRECTIONS->value];
        $this->assertSame('https://media.example.com/painting-directions/10/600/base.jpg', $steps[0]['photo_url']);
        $this->assertNull($steps[1]['photo_url']);
    }

    public function testNoBaseUrlMeansNoFiles(): void
    {
        $mapped = PaintingMapper::map($this->row(), ['media' => $this->media()], null);

        $this->assertSame([], $mapped['files']);
    }

    /**
     * @return array<int, object>
     */
    private function directions(): array
    {
        return [
            (object) ['id' => 11, 'directions' => 'Stars', 'sort_order' => 2],
            (object) ['id' => 12, 'directions' => ' A night sky. ', 'sort_order' => PaintingMapper::DESCRIPTION_SORT_ORDER],
            (object) ['id' => 10, 'directions' => 'Base coat', 'sort_order' => 1],
        ];
    }

    /**
     * @return array<int, object>
     */
    private function media(): array
    {
        return [
            (object) ['id' => 500, 'model_type' => 'painting', 'model_id' => 42, 'file_name' => 'old.jpg'],
            (object) ['id' => 501, 'model_type' => 'painting', 'model_id' => 42, 'file_name' => 'new.jpg'],
            (object) ['id' => 600, 'model_type' => 'painting-direction', 'model_id' => 10, 'file_name' => 'base.jpg'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'title' => 'Starry Night',
            'subtitle' => 'After Van Gogh',
            'difficulty' => 'Beginner',
            'duration' => '2 hours',
            'published_at' => '2021-03-02 09:00:00',
            'approved_at' => '2021-03-01 12:00:00',
            'created_at' => '2021-03-01 10:00:00',
            'deleted_at' => null,
        ]);
    }
}
