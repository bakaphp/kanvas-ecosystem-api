<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Neuron\Tools\EventSearchTool;
use Kanvas\Connectors\Intras\Reporting\EventoVersionDefinition;
use Tests\TestCase;

/**
 * The lookups that had no entry point before this tool existed.
 *
 * A real request — "las analíticas de la versión 5819, creo que el nombre es HELLO WELLNESS,
 * código EV3230, 2 de septiembre" — failed on every framing. Each failure had its own cause, so
 * each gets its own test here:
 *
 * - the stored name is `HELLOWELLNESS`, unspaced, so a literal LIKE on "HELLO WELLNESS" missed;
 * - `EV3230` is the legacy *event* id and survives only as a slug suffix (`hellowellness-3230`);
 * - the version actually being asked about was cancelled with no registrations, so anything
 *   reading the registration grain returns silence rather than "it was cancelled".
 *
 * The reporting connection is in no test's transact list, so rows are cleaned up explicitly.
 */
class EventSearchTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private EventSearchTool $tool;

    /** @var array<int, int> */
    private array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ReportSchemaService()->sync(
            new EventoVersionDefinition($this->kanvasApp->getId()),
            $this->kanvasApp->getId()
        );

        $this->tool = new EventSearchTool();
        $this->tool->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $this->seedVersions();
    }

    protected function tearDown(): void
    {
        if ($this->seeded !== []) {
            $this->table()->whereIn('version_id', $this->seeded)->delete();
        }

        parent::tearDown();
    }

    public function testFindsAnUnspacedNameFromASpacedSearch(): void
    {
        $result = $this->tool->__invoke(nombre: 'HELLO WELLNESS');

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['total'], 'all three versions of the event');
        $this->assertSame(
            ['HELLOWELLNESS', 'HELLOWELLNESS - VIRTUAL', 'HELLOWELLNESS - PRESENCIAL'],
            array_column($result['versiones'], 'version'),
            'newest first'
        );
    }

    public function testTheSipgoEventCodeResolvesThroughTheSlugSuffix(): void
    {
        $byCode = $this->tool->__invoke(codigo: 'EV3230');
        $byDigits = $this->tool->__invoke(codigo: '3230');

        $this->assertSame(3, $byCode['total']);
        $this->assertSame(
            array_column($byCode['versiones'], 'version_id'),
            array_column($byDigits['versiones'], 'version_id'),
            'EV3230 and 3230 are the same event'
        );
    }

    /**
     * SIPGO shows event ids prefixed and version ids bare, and a caller rarely knows which they
     * have, so a number matches either.
     */
    public function testALegacyVersionIdResolvesToThatOneVersion(): void
    {
        $result = $this->tool->__invoke(codigo: '5911');

        $this->assertSame(1, $result['total']);
        $this->assertSame('HELLOWELLNESS - PRESENCIAL', $result['versiones'][0]['version']);
        $this->assertStringContainsString('version_id=', $result['note']);
    }

    /**
     * The whole point of reading the version grain: this row does not exist in `inscripcion` at
     * all, and "it was cancelled" is the answer the person needs.
     */
    public function testACancelledVersionWithNoRegistrationsIsFoundAndExplained(): void
    {
        $result = $this->tool->__invoke(nombre: 'hellowellness', desde: '2025-09-01', hasta: '2025-09-30');

        $this->assertSame(1, $result['total']);

        $version = $result['versiones'][0];

        $this->assertSame('Cancelado', $version['estatus']);
        $this->assertSame(0, $version['inscripciones']);
        $this->assertStringContainsString('CANCELADA', $version['aviso']);
        $this->assertStringContainsString('dato faltante', $version['aviso'], 'must deny it is missing data');
    }

    public function testCancelledAndEmptyVersionsCanBeExcluded(): void
    {
        $result = $this->tool->__invoke(nombre: 'HELLO WELLNESS', solo_con_inscritos: true);

        $this->assertSame(1, $result['total']);
        $this->assertSame('HELLOWELLNESS - PRESENCIAL', $result['versiones'][0]['version']);
    }

    /**
     * An empty result that does not say "stop" is what makes the model re-query until the run
     * budget kills the turn.
     */
    public function testAnEmptyResultTellsTheModelNotToRetry(): void
    {
        $result = $this->tool->__invoke(nombre: 'evento que no existe en ninguna parte');

        $this->assertSame('success', $result['status'], 'no match is not an error');
        $this->assertSame(0, $result['total']);
        $this->assertStringContainsString('NO repitas', $result['note']);
    }

    public function testRequiresAtLeastOneCriterion(): void
    {
        $result = new EventSearchTool()->__invoke();

        $this->assertSame('error', $result['status']);
    }

    /**
     * Versions belong to the agency that ran them; the agency-4 copy of this event must not leak
     * into an agency-1 search.
     */
    public function testAnotherCompanysVersionIsNotReturned(): void
    {
        $result = $this->tool->__invoke(nombre: 'HELLO WELLNESS');

        $this->assertNotContains(
            $this->foreignVersionId(),
            array_column($result['versiones'], 'version_id')
        );
    }

    private function seedVersions(): void
    {
        $rows = [
            [$this->id(1), 5911, 'HELLOWELLNESS - PRESENCIAL', '2023-07-05', 'Completado', 168, $this->company->getId()],
            [$this->id(2), 5912, 'HELLOWELLNESS - VIRTUAL', '2023-07-05', 'Cancelado', 0, $this->company->getId()],
            [$this->id(3), 6498, 'HELLOWELLNESS', '2025-09-03', 'Cancelado', 0, $this->company->getId()],
            [$this->foreignVersionId(), 4621, 'HELLOWELLNESS', '2020-06-05', 'Completado', 115, $this->company->getId() + 90001],
        ];

        foreach ($rows as [$versionId, $legacyId, $version, $date, $status, $registrations, $companyId]) {
            $this->table()->insert([
                'version_id' => $versionId,
                'companies_id' => $companyId,
                'legacy_id' => $legacyId,
                'evento_id' => 9798,
                'evento' => 'HELLOWELLNESS',
                'codigo_evento' => 'hellowellness-3230',
                'version' => $version,
                'codigo_version' => 'hellowellness-v-' . $legacyId,
                'estatus' => $status,
                'fecha_inicio' => $date,
                'fecha_fin' => $date,
                'inscripciones' => $registrations,
                'asistentes' => $registrations,
                'empresas' => 0,
                'refreshed_at' => now(),
            ]);

            $this->seeded[] = $versionId;
        }
    }

    private function id(int $offset): int
    {
        return 990000 + $offset;
    }

    private function foreignVersionId(): int
    {
        return 990009;
    }

    private function table(): Builder
    {
        return DB::connection('reporting')->table(
            sprintf('rpt_evento_version_app%d', $this->kanvasApp->getId())
        );
    }
}
