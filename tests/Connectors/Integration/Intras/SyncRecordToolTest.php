<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Jobs\SyncSipgoRecordJob;
use Kanvas\Connectors\Intras\Neuron\Tools\EventRegistrationsTool;
use Kanvas\Connectors\Intras\Neuron\Tools\SyncRecordTool;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\SeedsSipgoEventGraph;
use Tests\TestCase;

#[Group('serial')]
final class SyncRecordToolTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsSipgoEventGraph;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'social', 'event', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $this->createSipgoSchema();
        $this->pointIntrasAtScratchDatabase();
        $this->prepareKanvasForSipgoEvents($this->kanvasApp, $this->company);
    }

    protected function tearDown(): void
    {
        $this->company->del(CustomFieldEnum::INTRAS_AGENCY_ID->value);
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function testASmallVersionSyncsInlineAndTheReadToolsThenShowTheSyncTime(): void
    {
        $versionId = random_int(9_000_000, 9_999_999);
        $this->seedSipgoVersion($versionId, [
            80239 => ['first' => 'Johanna', 'last' => 'Rodriguez Guzman', 'position' => 'Subgerente de Negocios', 'company' => 11608],
        ]);
        $link = 'https://erp.example.test/events-versions/' . $versionId . '/participants';

        $result = $this->tool(new SyncRecordTool())(referencia: $link);

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertSame('VE' . $versionId, $result['referencia']);
        $this->assertSame(1, $result['ejecutivos']);
        $this->assertNotEmpty($result['sincronizado_en']);
        $this->assertStringContainsString('intras_inscritos', $result['note']);

        $registrations = $this->tool(new EventRegistrationsTool())(referencia: $link);

        $this->assertSame('success', $registrations['status'], $registrations['message'] ?? '');
        $this->assertSame($result['sincronizado_en'], $registrations['sincronizado_en']);
        $this->assertSame('Subgerente de Negocios', $registrations['inscritos'][0]['cargo']);
        $this->assertSame('PA80239', $registrations['inscritos'][0]['pa']);
    }

    public function testALargeVersionIsQueuedInsteadOfRunInTheTurn(): void
    {
        Queue::fake();
        $versionId = random_int(9_000_000, 9_999_999);
        $participants = [];

        for ($i = 0; $i < 301; $i++) {
            $participants[8_000_000 + $i] = ['first' => 'Persona', 'last' => 'Numero ' . $i, 'company' => 11608];
        }

        $this->seedSipgoVersion($versionId, $participants);

        $result = $this->tool(new SyncRecordTool())(referencia: 'VE' . $versionId);

        $this->assertSame('queued', $result['status']);
        $this->assertGreaterThan(300, $result['filas']);
        $this->assertStringContainsString('en cola', $result['note']);
        Queue::assertPushed(SyncSipgoRecordJob::class, fn (SyncSipgoRecordJob $job): bool => $job->legacyId === $versionId && $job->kind === 'version');
    }

    public function testBadReferencesTalkBackAndARepeatInTheTurnIsNotRunTwice(): void
    {
        $tool = $this->tool(new SyncRecordTool());

        $garbage = $tool(referencia: 'HELLO WELLNESS');
        $this->assertSame('error', $garbage['status']);

        $missing = $tool(referencia: 'VE' . random_int(9_000_000, 9_999_999));
        $this->assertSame('error', $missing['status']);
        $this->assertStringContainsString('No reintentes', $missing['message']);

        $versionId = random_int(9_000_000, 9_999_999);
        $this->seedSipgoVersion($versionId, [80239 => ['first' => 'Johanna', 'last' => 'Rodriguez', 'company' => 11608]]);

        $first = $tool(referencia: 'VE' . $versionId);
        $second = $tool(referencia: 'VE' . $versionId);

        $this->assertSame('success', $first['status'], $first['message'] ?? '');
        $this->assertTrue($second['repeat_call'] ?? false, 'the second identical call in a turn returns the first result');
        $this->assertSame($first['sincronizado_en'], $second['sincronizado_en']);
    }

    /**
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function tool(object $tool): object
    {
        return $tool->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
