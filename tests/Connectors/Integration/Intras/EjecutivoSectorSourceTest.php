<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Reporting\EjecutivoDefinition;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

/**
 * `sector` on an ejecutivo is the company's line of business, not the person's neighbourhood.
 *
 * SIPGO stores a custom field literally called `sector` on the participant, and in the Dominican
 * Republic that word means the barrio — PIANTINI, NACO, GAZCUE. The organization has its own
 * `sector`, which is the business one. Reading the person's matched the company's on 3 of 24,301
 * rows and quietly turned every "por sector" report into a map of Santo Domingo neighbourhoods
 * presented as industries.
 *
 * The barrio is not dropped; it stays in `distrito`. This test pins both halves, because fixing
 * one by losing the other would be its own regression.
 */
class EjecutivoSectorSourceTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private People $person;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $this->organization = new Organization();
        $this->organization->apps_id = $this->kanvasApp->getId();
        $this->organization->companies_id = $this->company->getId();
        $this->organization->users_id = static::$cachedUser->getId();
        $this->organization->name = 'ACERO ESTRELLA TEST';
        $this->organization->saveOrFail();

        $this->organization->set('sector', 'CONSTRUCCION');
        $this->organization->set('tamano', 'grande');
        $this->organization->set('actividad', 'MANUFACTURA DE ACERO');

        $this->person = new People();
        $this->person->apps_id = $this->kanvasApp->getId();
        $this->person->companies_id = $this->company->getId();
        $this->person->users_id = static::$cachedUser->getId();
        $this->person->firstname = 'Sector';
        $this->person->lastname = 'Probe';
        $this->person->name = 'Sector Probe';
        $this->person->saveOrFail();

        // What SIPGO actually puts on the participant: the barrio.
        $this->person->set('sector', 'PIANTINI');

        DB::connection('crm')->table('organizations_peoples')->insert([
            'organizations_id' => $this->organization->getId(),
            'peoples_id' => $this->person->getId(),
            'created_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('crm')->table('organizations_peoples')
            ->where('peoples_id', $this->person->getId())->delete();
        // Nothing rolls these back — a leaked probe person is flattened into rpt_ejecutivo.
        $this->person->forceDelete();
        $this->organization->forceDelete();

        parent::tearDown();
    }

    public function testSectorIsTheCompanysLineOfBusinessNotThePersonsBarrio(): void
    {
        $row = $this->flattenedRow();

        $this->assertSame('CONSTRUCCION', $row['sector']);
        $this->assertNotSame('PIANTINI', $row['sector'], 'the barrio must never land in sector');
    }

    public function testTheBarrioIsStillAvailableAsDistrito(): void
    {
        $this->assertSame('PIANTINI', $this->flattenedRow()['distrito']);
    }

    /**
     * Both were read off the person too, and no person carries either — so the columns were
     * empty on all 123,813 rows rather than merely wrong.
     */
    public function testSizeAndActivityAlsoComeFromTheCompany(): void
    {
        $row = $this->flattenedRow();

        $this->assertSame('grande', $row['tamano']);
        $this->assertSame('MANUFACTURA DE ACERO', $row['actividad']);
    }

    /**
     * A person with no organization must not inherit another company's sector, and must not
     * fall back to their own barrio either.
     */
    public function testAPersonWithNoCompanyHasNoSector(): void
    {
        DB::connection('crm')->table('organizations_peoples')
            ->where('peoples_id', $this->person->getId())->delete();

        $row = $this->flattenedRow();

        $this->assertNull($row['sector']);
        $this->assertNull($row['tamano']);
        $this->assertSame('PIANTINI', $row['distrito'], 'the barrio is the person\'s own, so it stays');
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenedRow(): array
    {
        $rows = iterator_to_array(new EjecutivoDefinition($this->kanvasApp->getId())->rowsFor(
            $this->kanvasApp,
            $this->company,
            [$this->person->getId()]
        ));

        $this->assertCount(1, $rows, 'the probe person should flatten to exactly one row');

        return $rows[0];
    }
}
