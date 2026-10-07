<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics\Reporting;

use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Reporting\EjecutivoDefinition;
use Kanvas\Connectors\Intras\Reporting\InscripcionDefinition;
use Kanvas\Connectors\Intras\Reporting\IntrasReportDefinitionProvider;
use PHPUnit\Framework\TestCase;

class ReportSchemaServiceTest extends TestCase
{
    public function testTheTableNameCarriesTheAppSoNoAppsIdColumnIsNeeded(): void
    {
        $service = new ReportSchemaService();

        $this->assertSame('rpt_ejecutivo_app49', $service->tableFor(new EjecutivoDefinition(), 49));
        $this->assertSame('rpt_ejecutivo_app7', $service->tableFor(new EjecutivoDefinition(), 7));
    }

    /**
     * The app lives in the table name, so an apps_id column would be constant in every row and
     * dead weight at the front of every index. companies_id is the real discriminator.
     */
    public function testTheCreateStatementHasNoAppsIdButAlwaysHasCompaniesId(): void
    {
        $sql = new ReportSchemaService()->createStatement($this->definition(), 'rpt_demo_app1');

        $this->assertStringNotContainsString('apps_id', $sql);
        $this->assertStringContainsString('`companies_id` INT NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`demo_id`)', $sql);
        $this->assertStringContainsString('`refreshed_at` DATETIME NOT NULL', $sql);
    }

    public function testEveryOrdinaryIndexLeadsWithTheTenantColumn(): void
    {
        $sql = new ReportSchemaService()->createStatement($this->definition(), 'rpt_demo_app1');

        $this->assertStringContainsString('KEY `idx_nivel` (`companies_id`, `nivel`)', $sql);
    }

    /**
     * MySQL does not allow a multi-valued index to be composite, so those are the one exception
     * to the tenant-first rule.
     */
    public function testAMultiValuedIndexIsNotCompositeAndCastsToItsElementType(): void
    {
        $sql = new ReportSchemaService()->createStatement($this->definition(), 'rpt_demo_app1');

        $this->assertStringContainsString('KEY `idx_grupos` ((CAST(`grupos` AS CHAR(64) ARRAY)))', $sql);
        $this->assertStringNotContainsString('idx_grupos` (`companies_id`', $sql);
    }

    public function testAnUnindexedColumnGetsNoIndex(): void
    {
        $sql = new ReportSchemaService()->createStatement($this->definition(), 'rpt_demo_app1');

        $this->assertStringNotContainsString('idx_notas', $sql);
    }

    public function testTheEjecutivoDefinitionHasNoDuplicateColumnNames(): void
    {
        $names = array_map(fn (ReportColumn $c) => $c->name, new EjecutivoDefinition()->columns());

        $this->assertSame(
            array_unique($names),
            $names,
            'duplicates: ' . implode(',', array_diff_assoc($names, array_unique($names)))
        );
    }

    /**
     * pa_code is the legacy participants.id; tc_code is The Conference's own code from
     * participants_custom_fields. The Gestor labels both "Código" and they are different things.
     */
    public function testTheEjecutivoDefinitionKeepsTheTwoCodigoFieldsApart(): void
    {
        $names = array_map(fn (ReportColumn $c) => $c->name, new EjecutivoDefinition()->columns());

        $this->assertContains('pa_code', $names);
        $this->assertContains('tc_code', $names);
    }

    public function testTheEjecutivoGrainTellsAnAgentItIsOneRowPerPerson(): void
    {
        $definition = new EjecutivoDefinition();

        $this->assertSame(ReportGrainEnum::PERSON, $definition->grain());
        $this->assertStringContainsString('One row per person', $definition->grain()->description());
    }

    /**
     * The person × event grain must warn about COUNT(DISTINCT) — counting rows there counts
     * registrations, not people, which is the mistake SIPGO's own reports make.
     */
    public function testThePersonEventGrainWarnsAboutCountingRows(): void
    {
        $this->assertStringContainsString(
            'COUNT(DISTINCT)',
            ReportGrainEnum::PERSON_EVENT->description()
        );
    }

    public function testTheInscripcionDefinitionHasNoDuplicateColumnNames(): void
    {
        $names = array_map(fn (ReportColumn $c) => $c->name, new InscripcionDefinition()->columns());

        $this->assertSame(
            array_unique($names),
            $names,
            'duplicates: ' . implode(',', array_diff_assoc($names, array_unique($names)))
        );
    }

    /**
     * The 17-condition cluster only works because the person's attributes are copied onto the
     * registration row — otherwise every event filter would have to join back to `ejecutivo`.
     */
    public function testTheInscripcionRowCarriesThePersonColumnsItFiltersOn(): void
    {
        $names = array_map(fn (ReportColumn $c) => $c->name, new InscripcionDefinition()->columns());

        foreach (['peoples_id', 'pa_code', 'nivel', 'empresa_id', 'sector', 'nombre_completo'] as $person) {
            $this->assertContains($person, $names, "{$person} must be denormalized onto the registration");
        }
    }

    /**
     * Legacy uses IN (1,2,6,7,8,9,11,14) everywhere except participants_profiles, which drops 11
     * and 14 — two definitions of "attended". The flat column resolves it once, so it has to
     * exist and be indexed.
     */
    public function testAttendanceIsAResolvedIndexedColumnNotSomethingCallersRecompute(): void
    {
        $columns = [];

        foreach (new InscripcionDefinition()->columns() as $column) {
            $columns[$column->name] = $column;
        }

        $this->assertArrayHasKey('es_asistente', $columns);
        $this->assertTrue($columns['es_asistente']->indexed);
        $this->assertArrayHasKey('cupos', $columns, 'seats-vs-headcount must also be precomputed');
    }

    /**
     * A version runs on many dates. Keeping them as an array rather than a third grain is what
     * stops the row count multiplying by dates-per-version; DATE is the cast the index needs.
     */
    public function testTheDatesArrayIsIndexedAsDatesNotStrings(): void
    {
        $fechas = null;

        foreach (new InscripcionDefinition()->columns() as $column) {
            if ($column->name === 'fechas') {
                $fechas = $column;
            }
        }

        $this->assertNotNull($fechas);
        $this->assertTrue($fechas->multiValued);
        $this->assertSame('DATE', $fechas->indexCast);
    }

    /**
     * Widening is the normal case as a definition evolves — a longer name field, more decimal
     * precision. Before MODIFY support the only path was DROP + recreate, which on a populated
     * table means deleting the data and 500ing the Gestor until the rebuild finishes.
     */
    public function testWideningATypeIsRecognisedAsSafe(): void
    {
        $service = new class () extends ReportSchemaService {
            public function widens(string $from, string $to): bool
            {
                return $this->isWidening($from, $to);
            }
        };

        $this->assertTrue($service->widens('varchar(32)', 'varchar(255)'));
        $this->assertTrue($service->widens('int', 'bigint'));
        $this->assertTrue($service->widens('varchar(255)', 'text'));
        $this->assertTrue($service->widens('decimal(10,2)', 'decimal(14,2)'));
    }

    /**
     * Narrowing truncates silently — MySQL cuts the tail off every longer value. Anything not
     * provably roomier is treated as narrowing, so an unrecognised type change needs the
     * explicit flag rather than a guess.
     */
    public function testAnythingNotProvablyRoomierCountsAsNarrowing(): void
    {
        $service = new class () extends ReportSchemaService {
            public function widens(string $from, string $to): bool
            {
                return $this->isWidening($from, $to);
            }
        };

        $this->assertFalse($service->widens('varchar(255)', 'varchar(64)'));
        $this->assertFalse($service->widens('bigint', 'int'));
        $this->assertFalse($service->widens('text', 'varchar(255)'));
        $this->assertFalse($service->widens('decimal(14,4)', 'decimal(14,2)'));
        // A type-family change is not something to guess about.
        $this->assertFalse($service->widens('varchar(64)', 'date'));
    }

    /**
     * MySQL reports `int` where a definition may say `int(11)`, and appends `unsigned`. Without
     * normalising, every sync would emit a pointless MODIFY on those columns.
     */
    public function testEquivalentTypeSpellingsAreNotTreatedAsAChange(): void
    {
        $service = new class () extends ReportSchemaService {
            public function same(string $a, string $b): bool
            {
                return $this->sameType($a, $b);
            }
        };

        $this->assertTrue($service->same('int(11)', 'int'));
        $this->assertTrue($service->same('VARCHAR(64)', 'varchar(64)'));
        $this->assertTrue($service->same('bigint unsigned', 'bigint'));
        $this->assertFalse($service->same('varchar(64)', 'varchar(128)'));
    }

    /**
     * Scoping is what makes these models safe to hand an agent: one whose connector the app has
     * not configured is not discoverable, so there is no path from tool input to another
     * tenant's tables.
     */
    public function testAnAppWithoutTheConnectorConfiguredGetsNoDefinitions(): void
    {
        $provider = new IntrasReportDefinitionProvider();

        $app = new class () extends Apps {
            public function getId(): mixed
            {
                return 999999;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }
        };

        $this->assertSame([], $provider->definitionsFor($app));
    }

    /**
     * Order is load-bearing: a definition that reads a flattened table must come after the one
     * that builds it, or it silently produces nulls rather than failing.
     */
    public function testDefinitionsAreOrderedSoDependentsComeAfterTheirSource(): void
    {
        $provider = new IntrasReportDefinitionProvider();

        $app = new class () extends Apps {
            public function getId(): mixed
            {
                return 49;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return 'configured';
            }
        };

        $order = array_map(fn ($d) => $d->model(), $provider->definitionsFor($app));
        $position = array_flip($order);

        $this->assertLessThan($position['inscripcion'], $position['ejecutivo']);
        $this->assertLessThan($position['empresa_plan'], $position['empresa']);
        $this->assertLessThan($position['empresa_oficina'], $position['empresa']);
        $this->assertLessThan($position['facilitador_asignacion'], $position['evento_version']);
        $this->assertLessThan($position['facilitador_asignacion'], $position['facilitador']);
        $this->assertLessThan($position['cortesia'], $position['ejecutivo']);
    }

    private function definition(): ReportDefinitionInterface
    {
        return new class () implements ReportDefinitionInterface {
            public function model(): string
            {
                return 'demo';
            }

            public function label(): string
            {
                return 'Demo';
            }

            public function grain(): ReportGrainEnum
            {
                return ReportGrainEnum::PERSON;
            }

            public function primaryKey(): string
            {
                return 'demo_id';
            }

            public function columns(): array
            {
                return [
                    ReportColumn::string('nivel', 64, 'Nivel', indexed: true),
                    ReportColumn::text('notas'),
                    ReportColumn::jsonArray('grupos', 'CHAR(64)', 'Grupos'),
                ];
            }
        };
    }
}
