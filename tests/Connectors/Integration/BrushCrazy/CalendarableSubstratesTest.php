<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCalendarableFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Tests\TestCase;

final class CalendarableSubstratesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'event', 'ecosystem'];

    private SyncCalendarableFromBrushCrazyAction $sync;

    private int $lessonId;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new SeedBrushCrazyLookupDataAction($app, $company, $user)->execute();

        $this->sync = new SyncCalendarableFromBrushCrazyAction(
            $app,
            $user,
            new StudioContext(
                bcStudioId: 2,
                company: $company,
                branch: $company->defaultBranch,
                themeArea: ThemeArea::where('companies_id', $company->getId())
                    ->where('apps_id', $app->getId())
                    ->where('name', SeedBrushCrazyLookupDataAction::UNASSIGNED)
                    ->firstOrFail(),
                timezone: 'America/Denver',
            ),
            Carbon::now('UTC'),
        );

        // Source ids end up in custom fields, and Redis never rolls back.
        $this->lessonId = random_int(10_000_000, 99_999_999);
    }

    public function testStoresTheOptionsAndPricesTheVersionAtTheCheapest(): void
    {
        $version = $this->sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson([
            $this->substrate(1, '16x20 Canvas', 3500),
            $this->substrate(2, '11x14 Canvas', 2500),
        ]));

        $this->assertSame(25.0, (float) $version->price_per_ticket);
        $this->assertSame(
            ['16x20 Canvas', '11x14 Canvas'],
            array_column($version->get(CustomFieldEnum::BRUSHCRAZY_SUBSTRATES->value), 'name')
        );
    }

    public function testRemovingEveryOptionClearsTheSnapshot(): void
    {
        $this->sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson([$this->substrate(1, 'RSVP', 0)]));
        $version = $this->sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson([]));

        $this->assertNull($version->get(CustomFieldEnum::BRUSHCRAZY_SUBSTRATES->value));
    }

    /** A caller that never read calendarable_substrates must not be taken as "the class has none". */
    public function testARowWithoutLoadedSubstratesLeavesTheSnapshotAlone(): void
    {
        $this->sync->fromRow(CalendarableTypeEnum::LESSON, $this->lesson([$this->substrate(1, '16x20 Canvas', 3500)]));

        $row = $this->lesson([]);
        unset($row->substrates);
        $version = $this->sync->fromRow(CalendarableTypeEnum::LESSON, $row);

        $this->assertCount(1, $version->get(CustomFieldEnum::BRUSHCRAZY_SUBSTRATES->value));
        $this->assertSame(35.0, (float) $version->fresh()->price_per_ticket);
    }

    /**
     * @param  array<int, object>  $substrates
     */
    private function lesson(array $substrates): object
    {
        return (object) [
            'id' => $this->lessonId,
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
            'substrates' => $substrates,
        ];
    }

    private function substrate(int $sortOrder, string $name, int $cents): object
    {
        return (object) [
            'id' => $this->lessonId * 10 + $sortOrder,
            'name' => $name,
            'price' => json_encode(['amount' => $cents, 'currency' => 'USD']),
            'sort_order' => $sortOrder,
            'product_id' => null,
        ];
    }
}
