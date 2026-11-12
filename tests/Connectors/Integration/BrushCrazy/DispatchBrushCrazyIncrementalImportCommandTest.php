<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use App\Console\Commands\Connectors\BrushCrazy\DispatchBrushCrazyIncrementalImportCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\ProvisionBrushCrazyReceiverAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\CorrectionCutoff;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Webhooks\ProcessBrushCrazyWebhookJob;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Serial: the cutover case writes an app setting, which lives in Redis and on `ecosystem` and is
 * rolled back by neither.
 */
#[Group('serial')]
final class DispatchBrushCrazyIncrementalImportCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const int STUDIO_ID = 2;

    private const array APP_SETTINGS = [
        ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF,
        ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT,
        ConfigurationEnum::BRUSHCRAZY_DB_HOST,
        ConfigurationEnum::BRUSHCRAZY_DB_DATABASE,
    ];

    protected $connectionsToTransact = [null, 'workflow'];

    private Apps $kanvasApp;

    private Companies $company;

    private mixed $originalStudioId;

    private mixed $originalSyncEnabled;

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessBrushCrazyWebhookJob::class],
            ['name' => 'BrushCrazy Mirror Webhook'],
        );

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->originalStudioId = $this->company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);
        $this->originalSyncEnabled = $this->company->get(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);

        foreach (self::APP_SETTINGS as $key) {
            $this->originalAppSettings[$key->value] = $this->kanvasApp->get($key->value);
        }

        $this->kanvasApp->set(ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF->value, '2026-10-01T00:00:00+00:00');

        $this->company->set(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, self::STUDIO_ID);
        $this->provisionReceiver();
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);
    }

    protected function tearDown(): void
    {
        $this->restore(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value, $this->originalStudioId);
        $this->restore(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, $this->originalSyncEnabled);

        foreach ($this->originalAppSettings as $key => $value) {
            $value !== null ? $this->kanvasApp->set($key, $value) : $this->kanvasApp->del($key);
        }

        parent::tearDown();
    }

    /**
     * Regression: the scheduled `--entity=all` run pinned the cutoff at the tick's own instant,
     * an irreversible boundary no operator chose.
     */
    public function testAppWithoutAPinnedCorrectionCutoffIsNotPulled(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF->value);

        $this->assertArrayNotHasKey($this->kanvasApp->getId(), DispatchBrushCrazyIncrementalImportCommand::targets());
        $this->assertNull(CorrectionCutoff::stored($this->kanvasApp));
    }

    public function testInactiveReceiverIsNotPulled(): void
    {
        ReceiverWebhook::query()
            ->forJob(ProcessBrushCrazyWebhookJob::class)
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->update(['is_active' => false]);

        $targets = DispatchBrushCrazyIncrementalImportCommand::targets();

        $this->assertNotContains(self::STUDIO_ID, $targets[$this->kanvasApp->getId()]['studios'] ?? []);
    }

    /**
     * The import turns its own failures into an exit code; a cursor frozen behind a row that keeps
     * failing has to surface somewhere other than the cron's discarded output.
     */
    public function testAFailingImportIsReported(): void
    {
        Exceptions::fake();
        $this->kanvasApp->del(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value);
        $this->kanvasApp->del(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value);

        $this->artisan('kanvas:brushcrazy-incremental')->assertExitCode(DispatchBrushCrazyIncrementalImportCommand::FAILURE);

        Exceptions::assertReported(
            fn (RuntimeException $e) => str_contains($e->getMessage(), 'failed for app ' . $this->kanvasApp->getId())
        );
    }

    public function testFailureSummaryKeepsTheLastNonBlankLines(): void
    {
        $summary = DispatchBrushCrazyIncrementalImportCommand::summary("a\n\nb\r\n" . implode("\n", range(1, 10)) . "\n");

        $this->assertSame('3 | 4 | 5 | 6 | 7 | 8 | 9 | 10', $summary);
    }

    public function testMirroredStudioIsTargetedAsAReceiverUser(): void
    {
        $target = DispatchBrushCrazyIncrementalImportCommand::targets()[$this->kanvasApp->getId()] ?? null;

        $this->assertNotNull($target);
        $this->assertSame($this->kanvasApp->getId(), $target['app']->getId());
        $this->assertContains(self::STUDIO_ID, $target['studios']);
        $this->assertContains($target['user']->getId(), $this->receiverUserIds());
    }

    public function testStudioWithSyncDisabledIsNotPulled(): void
    {
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, false);

        $targets = DispatchBrushCrazyIncrementalImportCommand::targets();

        $this->assertNotContains(self::STUDIO_ID, $targets[$this->kanvasApp->getId()]['studios'] ?? []);
    }

    public function testAppPastCutoverIsNotPulled(): void
    {
        $now = Carbon::now('UTC');
        $this->kanvasApp->set(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value, $now->copy()->addDay()->toIso8601String());

        $this->assertArrayHasKey($this->kanvasApp->getId(), DispatchBrushCrazyIncrementalImportCommand::targets($now));
        $this->assertArrayNotHasKey(
            $this->kanvasApp->getId(),
            DispatchBrushCrazyIncrementalImportCommand::targets($now->copy()->addDays(2))
        );
    }

    private function provisionReceiver(): void
    {
        new ProvisionBrushCrazyReceiverAction(
            $this->kanvasApp,
            auth()->user(),
            new StudioContext(
                bcStudioId: self::STUDIO_ID,
                company: $this->company,
                branch: $this->company->defaultBranch,
                themeArea: new ThemeArea(),
                timezone: 'America/New_York',
            ),
        )->execute();
    }

    /**
     * @return array<int, int>
     */
    private function receiverUserIds(): array
    {
        return ReceiverWebhook::query()
            ->forJob(ProcessBrushCrazyWebhookJob::class)
            ->fromApp($this->kanvasApp)
            ->notDeleted()
            ->pluck('users_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function restore(string $key, mixed $original): void
    {
        $original !== null ? $this->company->set($key, $original) : $this->company->del($key);
    }
}
