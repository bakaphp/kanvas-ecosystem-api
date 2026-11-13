<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Concerns\SyncsAttachedPhotos;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Themes\Models\ThemeArea;
use Tests\TestCase;

/**
 * Exercised on a class's EventVersion, the entity the calendarable photo sync writes to. The
 * BRUSHCRAZY_MEDIA_BASE_URL setting that turns photo sync on is shared across parallel tests, so
 * the trait is driven directly with already-built photo URLs.
 */
final class SyncsAttachedPhotosTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'event', 'ecosystem'];

    private EventVersion $version;

    private object $syncer;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new SeedBrushCrazyLookupDataAction($app, $company, $user)->execute();

        $context = new StudioContext(
            bcStudioId: 2,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: ThemeArea::where('companies_id', $company->getId())
                ->where('apps_id', $app->getId())
                ->where('name', SeedBrushCrazyLookupDataAction::UNASSIGNED)
                ->firstOrFail(),
            timezone: 'America/Denver',
        );

        // Source ids end up in custom fields, and Redis never rolls back.
        $lessonId = random_int(10_000_000, 99_999_999);

        $this->version = new SyncCalendarableFromBrushCrazyAction(
            $app,
            $user,
            $context,
            Carbon::now('UTC'),
        )->fromRow(CalendarableTypeEnum::LESSON, (object) [
            'id' => $lessonId,
            'studio_id' => 2,
            'name' => 'Starry Night',
            'description' => null,
            'type' => null,
            'start' => '2025-03-01 01:00:00',
            'end' => '2025-03-01 03:00:00',
            'occupancy' => 20,
            'painting_id' => null,
            'assignee_id' => null,
            'published_at' => '2025-02-01 00:00:00',
            'rsvp' => 0,
            'private' => 0,
            'fundraiser' => 0,
            'password' => null,
            'deleted_at' => null,
        ]);

        $this->syncer = new class () {
            use SyncsAttachedPhotos;

            public function sync(BaseModel $entity, array $files): void
            {
                $this->syncPhotos($entity, $files);
            }
        };
    }

    public function testAttachesThePhotoAndRemembersItsMediaId(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertNotNull($this->version->getFileByName('photo'));
        $this->assertSame(['photo' => 101], $this->attachedMedia());
    }

    public function testReSyncingTheSamePhotoDoesNotAttachItAgain(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertCount(1, $this->version->getFiles());
    }

    public function testAReplacedPhotoDetachesTheOldOne(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $this->syncer->sync($this->version, $this->photo(102));

        $this->assertCount(1, $this->version->getFiles());
        $this->assertSame(['photo' => 102], $this->attachedMedia());
    }

    public function testARemovedPhotoIsDetachedAndForgotten(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $this->syncer->sync($this->version, []);

        $this->assertCount(0, $this->version->getFiles());
        $this->assertNull($this->version->get(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value));
    }

    public function testRemembersTheUrlItAttached(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertSame(
            $this->photo(101)['photo']['url'],
            $this->version->get(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value)['photo']['url']
        );
    }

    /** Only the link to the url the mirror attached is removed, never a photo uploaded in Kanvas. */
    public function testARemovedPhotoLeavesAPhotoUploadedInKanvas(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $userUrl = 'https://media.example.com/uploads/' . $this->version->getId() . '/mine.jpg';
        $this->version->addFileFromUrl($userUrl, 'photo', $this->version->app);

        $this->syncer->sync($this->version, []);

        $this->assertSame($userUrl, $this->version->getFileByName('photo')?->url);
    }

    /**
     * Kanvas first, mirror second: with duplicate-by-name off the attach would repoint the field's
     * live link at the mirror's file and the uploaded photo would be gone.
     */
    public function testTheMirrorDoesNotReplaceAPhotoUploadedInKanvas(): void
    {
        $userUrl = 'https://media.example.com/uploads/' . $this->version->getId() . '/mine.jpg';
        $this->version->addFileFromUrl($userUrl, 'photo', $this->version->app);

        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertSame($userUrl, $this->version->getFileByName('photo')?->url);
        $this->assertCount(1, $this->version->getFiles());
        $this->assertNull($this->version->get(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value));
    }

    /** The upload took over the mirror's link; a later source photo change must not take it back. */
    public function testAChangedSourcePhotoDoesNotReplaceAKanvasUploadThatTookOverTheField(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $userUrl = 'https://media.example.com/uploads/' . $this->version->getId() . '/mine.jpg';
        $this->version->addFileFromUrl($userUrl, 'photo', $this->version->app);

        $this->syncer->sync($this->version, $this->photo(102));

        $this->assertSame($userUrl, $this->version->getFileByName('photo')?->url);
    }

    public function testTheMirrorPhotoIsAttachedOnceTheKanvasOneIsRemoved(): void
    {
        $userUrl = 'https://media.example.com/uploads/' . $this->version->getId() . '/mine.jpg';
        $this->version->addFileFromUrl($userUrl, 'photo', $this->version->app);
        $this->syncer->sync($this->version, $this->photo(101));

        $this->version->getFileByName('photo')->softDelete();
        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertSame($this->photo(101)['photo']['url'], $this->version->getFileByName('photo')?->url);
        $this->assertSame(['photo' => 101], $this->attachedMedia());
    }

    /** A row written before urls were recorded holds only the media id and must still be read. */
    public function testALegacyMediaIdOnlyEntryIsNotReattached(): void
    {
        $this->syncer->sync($this->version, $this->photo(101));
        $this->version->set(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value, ['photo' => 101]);

        $this->syncer->sync($this->version, $this->photo(101));

        $this->assertCount(1, $this->version->getFiles());
    }

    /**
     * @return array<string, array{media_id: int, url: string}>
     */
    private function photo(int $mediaId): array
    {
        return [
            'photo' => [
                'media_id' => $mediaId,
                'url' => 'https://media.example.com/lessons/' . $this->version->getId() . '/' . $mediaId . '/photo.jpg',
            ],
        ];
    }

    private function attachedMedia(): mixed
    {
        $attached = $this->version->get(CustomFieldEnum::BRUSHCRAZY_ATTACHED_MEDIA->value);

        return is_array($attached) ? array_map(fn (array $photo) => (int) $photo['media_id'], $attached) : $attached;
    }
}
