<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Analytics\Reporting\Concerns\ReadsFlatReportTables;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Reporting\EmpresaDefinition;
use Tests\TestCase;

/**
 * A definition that denormalises from a parent grain looks the parent up by its key. Entity ids
 * are globally unique, so without a tenant predicate that lookup happily returns another
 * company's row.
 *
 * It was not theoretical. Six definitions each grew their own copy of the lookup and none
 * filtered `companies_id`; `empresa_plan` then yielded all 42 plan-holding organizations to every
 * company's rebuild. Because its primary key is derived from the organization id alone, each
 * company's run overwrote the previous one's rows — after four rebuilds the table held a single
 * company's stamp on another company's plans.
 *
 * The reporting connection is in no test's transact list, so rows are cleaned up explicitly.
 */
class FlatTableTenantScopingTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private EmpresaDefinition $definition;

    /** @var array<int, int> */
    private array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->definition = new EmpresaDefinition($this->kanvasApp->getId());

        new ReportSchemaService()->sync($this->definition, $this->kanvasApp->getId());
    }

    protected function tearDown(): void
    {
        if ($this->seeded !== []) {
            $this->table()->whereIn('organizations_id', $this->seeded)->delete();
        }

        parent::tearDown();
    }

    public function test_a_row_belonging_to_another_company_is_not_returned(): void
    {
        $organizationId = random_int(800000000, 899999999);
        $otherCompanyId = $this->company->getId() + 1;

        // The same key, written under a different tenant — exactly what a globally unique
        // entity id makes possible.
        $this->seedEmpresa($organizationId, $otherCompanyId);

        $found = $this->reader()->read('empresa', 'organizations_id', [$organizationId], $this->company);

        $this->assertSame([], $found, 'the lookup must not cross the tenant boundary');
    }

    public function test_a_row_belonging_to_this_company_is_returned(): void
    {
        $organizationId = random_int(700000000, 799999999);

        $this->seedEmpresa($organizationId, $this->company->getId());

        $found = $this->reader()->read('empresa', 'organizations_id', [$organizationId], $this->company);

        $this->assertArrayHasKey($organizationId, $found);
    }

    public function test_an_empty_id_list_does_not_query(): void
    {
        $this->assertSame([], $this->reader()->read('empresa', 'organizations_id', [], $this->company));
    }

    private function seedEmpresa(int $organizationId, int $companyId): void
    {
        $this->table()->insert([
            'organizations_id' => $organizationId,
            'companies_id' => $companyId,
            'nombre' => 'Tenant fixture ' . $organizationId,
            'refreshed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->seeded[] = $organizationId;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        $schema = new ReportSchemaService();

        return $schema->connection()->table($schema->tableFor($this->definition, $this->kanvasApp->getId()));
    }

    /**
     * The trait's method is protected, so the test drives it through a thin public seam rather
     * than reaching in with reflection.
     */
    private function reader(): object
    {
        return new class ($this->kanvasApp->getId()) {
            use ReadsFlatReportTables;

            public function __construct(private readonly int $appId)
            {
            }

            /**
             * @param array<int, int> $ids
             *
             * @return array<int, object>
             */
            public function read(string $model, string $keyColumn, array $ids, Companies $company): array
            {
                return $this->flatRows($model, $keyColumn, $ids, $company);
            }
        };
    }
}
