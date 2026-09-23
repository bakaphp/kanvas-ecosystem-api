<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Kanvas\Imports\Jobs\RunImportSourceJob;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\TestCase;

final class RunImportSourcesCommandTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testQueuesADueSourceOnceAndStampsIt(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);
        $source->forceFill(['last_run_at' => Carbon::parse('2026-09-21 05:10:00', 'UTC')])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-22 05:05:00', 'UTC'));

        $this->artisan('kanvas:imports:run-sources', ['--source' => $source->getId()])->assertSuccessful();
        $this->artisan('kanvas:imports:run-sources', ['--source' => $source->getId()])->assertSuccessful();

        Queue::assertPushed(RunImportSourceJob::class, 1);
        Queue::assertPushed(
            RunImportSourceJob::class,
            fn (RunImportSourceJob $job) => $job->source->is($source) && $job->queue === 'imports'
        );
        $this->assertSame(ImportRunStatusEnum::QUEUED, $source->refresh()->last_status);
    }

    public function testSkipsASourceThatIsNotDueUnlessForced(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);
        $source->forceFill(['last_run_at' => Carbon::parse('2026-09-22 05:00:00', 'UTC')])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00', 'UTC'));

        $this->artisan('kanvas:imports:run-sources', ['--source' => $source->getId()])->assertSuccessful();
        Queue::assertNotPushed(RunImportSourceJob::class);

        $this->artisan('kanvas:imports:run-sources', ['--source' => $source->getId(), '--force' => true])->assertSuccessful();
        Queue::assertPushed(RunImportSourceJob::class, 1);
    }

    public function testDryRunNeedsASource(): void
    {
        $this->artisan('kanvas:imports:run-sources', ['--dry-run' => true])
            ->expectsOutputToContain('--dry-run needs --source')
            ->assertFailed();
    }
}
