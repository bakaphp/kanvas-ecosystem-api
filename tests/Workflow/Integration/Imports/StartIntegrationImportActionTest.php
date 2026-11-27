<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Actions\StartIntegrationImportAction;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;
use Tests\Workflow\Integration\Imports\Fakes\FakeImportRegistry;

final class StartIntegrationImportActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;
    use ImportsTestSetup;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private IntegrationsCompany $row;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeImportRegistry::reset();
        $this->row = $this->createIntegrationRows();
    }

    public function testQueuesARunWithTheNormalizedOptions(): void
    {
        $run = $this->start('chunky', ['region_ids' => ['1', '2']]);

        $this->assertSame(IntegrationImportStatusEnum::QUEUED, $run->status);
        $this->assertSame('chunky', $run->entity);
        $this->assertSame(['region_ids' => ['1', '2'], 'prepared' => true], $run->options);
        $this->assertNotNull($run->queued_at);
        Queue::assertPushed(
            RunIntegrationImportJob::class,
            fn (RunIntegrationImportJob $job) => $job->offset === 0 && $job->run->getId() === $run->getId()
        );
    }

    public function testNullOrEmptyOptionsAreNoOptions(): void
    {
        $run = $this->start('plain', []);

        $this->assertSame(IntegrationImportStatusEnum::QUEUED, $run->status);

        $chunky = $this->start('chunky', ['region_ids' => ['1'], 'flag' => null]);

        $this->assertArrayNotHasKey('flag', $chunky->fresh()->options);
        Queue::assertPushed(RunIntegrationImportJob::class);
    }

    public function testUnknownEntityIsRefused(): void
    {
        $this->assertRefused('fake cannot import products. It can import: chunky, plain, whole.', 'products');
    }

    public function testUnknownOptionIsRefused(): void
    {
        $this->assertRefused('plain does not take the option flag.', 'plain', ['flag' => true]);
    }

    public function testMissingRequiredOptionIsRefused(): void
    {
        $this->assertRefused('chunky needs the option region_ids.', 'chunky', ['flag' => true]);
    }

    public function testOptionTypesAreEnforced(): void
    {
        $this->assertRefused('The option region_ids must be a list of choices.', 'chunky', ['region_ids' => '1']);
        $this->assertRefused(
            'The option flag must be boolean.',
            'chunky',
            ['region_ids' => ['1'], 'flag' => 'yes']
        );
    }

    public function testAnInactiveIntegrationIsRefused(): void
    {
        $this->row->is_active = false;
        $this->row->save();

        $this->assertRefused('This integration is not active.', 'plain');
    }

    public function testARunningImportOfTheSameEntityBlocksAnotherRowOfTheSameIntegration(): void
    {
        $this->start('plain');

        $other = IntegrationsCompany::create([
            'apps_id' => $this->row->apps_id,
            'companies_id' => $this->row->companies_id,
            'integrations_id' => $this->row->integrations_id,
            'region_id' => Regions::create([
                'apps_id' => $this->row->apps_id,
                'companies_id' => $this->row->companies_id,
                'users_id' => 0,
                'name' => 'Second ' . uniqid(),
                'is_default' => 0,
                'is_deleted' => 0,
            ])->getId(),
            'status_id' => $this->row->status_id,
        ]);

        $this->assertRefused('A plain import is already in progress for this integration.', 'plain', row: $other);
        $this->start('whole');
        Queue::assertPushed(RunIntegrationImportJob::class, 2);
    }

    public function testASoftDeletedRunDoesNotBlockNorShowAsLatest(): void
    {
        $run = $this->start('plain');
        $run->is_deleted = true;
        $run->save();

        $this->assertNull(IntegrationImport::inProgressFor($this->row, 'plain'));
        $this->assertNull(IntegrationImport::latestFor($this->row, 'plain'));
    }

    public function testAStaleRunDoesNotBlock(): void
    {
        $this->start('plain');
        Carbon::setTestNow(Carbon::now()->addHours(IntegrationImport::STALE_AFTER_HOURS)->addMinute());

        try {
            $this->assertSame(IntegrationImportStatusEnum::QUEUED, $this->start('plain')->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAStartThatCannotGetTheLockIsRefusedAsInProgress(): void
    {
        $lock = Cache::lock(
            sprintf(
                'integration-import-start:%d:%d:plain',
                $this->row->companies_id,
                $this->row->integrations_id
            ),
            10
        );
        $this->assertTrue($lock->get());

        try {
            $this->assertRefused('A plain import is already in progress for this integration.', 'plain');
        } finally {
            $lock->release();
        }

        Queue::assertNotPushed(RunIntegrationImportJob::class);
    }

    public function testARefusedStartPreparesNothing(): void
    {
        $this->start('chunky', ['region_ids' => ['1']]);
        $this->assertSame(1, FakeImportRegistry::$prepared);

        $this->assertRefused(
            'A chunky import is already in progress for this integration.',
            'chunky',
            ['region_ids' => ['2']]
        );

        $this->assertSame(1, FakeImportRegistry::$prepared);
    }

    public function testPrepareRunsBeforeQueuingAndItsOutputIsStored(): void
    {
        $run = $this->start('chunky', ['region_ids' => ['1']]);

        $this->assertTrue($run->fresh()->options['prepared']);
    }

    private function start(string $entity, array $options = [], ?IntegrationsCompany $row = null): IntegrationImport
    {
        return new StartIntegrationImportAction(
            $row ?? $this->row,
            Users::getById(auth()->id()),
            $entity,
            $options,
        )->execute();
    }

    private function assertRefused(
        string $message,
        string $entity,
        array $options = [],
        ?IntegrationsCompany $row = null
    ): void {
        try {
            $this->start($entity, $options, $row);
            $this->fail('Expected a refusal: ' . $message);
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
