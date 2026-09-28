<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Analytics\Reporting\Services\ReportRefreshService;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Reporting\EjecutivoDefinition;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * The write path: Kanvas entities -> one flat row.
 *
 * The EAV pivot that the flat table exists to avoid runs here, on the write path, once per
 * person — instead of on every Gestor query.
 *
 * The reporting connection is in no test's transact list, so rows are cleaned up explicitly.
 */
class EjecutivoRefreshTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private EjecutivoDefinition $definition;
    private ReportRefreshService $refresh;
    private People $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->definition = new EjecutivoDefinition();
        $this->refresh = new ReportRefreshService();

        new ReportSchemaService()->sync($this->definition, $this->kanvasApp->getId());

        $this->person = $this->seedPerson();
    }

    protected function tearDown(): void
    {
        $this->flatRows()->where('peoples_id', $this->person->getId())->delete();
        $this->person->delete();

        parent::tearDown();
    }

    public function test_a_person_becomes_one_flat_row_with_its_custom_fields_pivoted(): void
    {
        $written = $this->refresh->refresh(
            $this->definition,
            $this->kanvasApp,
            $this->company,
            [(int) $this->person->getId()]
        );

        $this->assertSame(1, $written);

        $row = $this->flatRow();

        $this->assertSame('40123', $row->pa_code);
        $this->assertSame('Gerencial', $row->nivel);
        $this->assertSame('Recursos Humanos', $row->area);
        $this->assertNotNull($row->refreshed_at, 'every row is stamped so a rebuild can prune stale ones');
    }

    /**
     * The 0/1 prospect flag is what the Gestor renders as CLIENTE / POTENCIAL. Resolving it at
     * refresh means the UI and the agent cannot disagree about which is which.
     */
    public function test_the_prospect_flag_resolves_to_the_word_the_gestor_shows(): void
    {
        $this->person->set('intras_is_prospect', false);
        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $this->person->getId()]);

        $this->assertSame('CLIENTE', $this->flatRow()->relacion_comercial);

        $this->person->set('intras_is_prospect', true);
        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $this->person->getId()]);

        $this->assertSame('POTENCIAL', $this->flatRow()->relacion_comercial);
    }

    /**
     * Custom fields come back as strings, so a naive cast makes "0" truthy and every boolean
     * filter returns everyone.
     */
    public function test_a_false_flag_stored_as_a_string_stays_false(): void
    {
        $this->person->set('contacto_clave_axis', false);
        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $this->person->getId()]);

        $this->assertSame(0, (int) $this->flatRow()->contacto_clave_axis);
    }

    public function test_refreshing_twice_updates_in_place_rather_than_duplicating(): void
    {
        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $this->person->getId()]);

        $this->person->set('nivel', 'Ejecutivo');
        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $this->person->getId()]);

        $this->assertSame(1, $this->flatRows()->where('peoples_id', $this->person->getId())->count());
        $this->assertSame('Ejecutivo', $this->flatRow()->nivel);
    }

    /**
     * An Organization rename invalidates every person in it — the fan-out the refresh has to
     * batch rather than run on the save.
     */
    public function test_the_definition_declares_what_invalidates_a_row(): void
    {
        $invalidators = $this->definition->invalidatedBy();

        $this->assertArrayHasKey(People::class, $invalidators);
        $this->assertArrayHasKey(\Kanvas\Guild\Organizations\Models\Organization::class, $invalidators);
        $this->assertSame(
            [(int) $this->person->getId()],
            $invalidators[People::class]($this->person)
        );
    }

    private function flatRow(): object
    {
        return $this->flatRows()->where('peoples_id', $this->person->getId())->first();
    }

    private function flatRows(): \Illuminate\Database\Query\Builder
    {
        $schema = new ReportSchemaService();

        return $schema->connection()->table($schema->tableFor($this->definition, $this->kanvasApp->getId()));
    }

    private function seedPerson(): People
    {
        $person = new People();
        $person->apps_id = $this->kanvasApp->getId();
        $person->companies_id = $this->company->getId();
        $person->users_id = static::$cachedUser->getId();
        $person->firstname = 'Refresh';
        $person->lastname = 'Target ' . uniqid();
        $person->name = $person->firstname . ' ' . $person->lastname;
        $person->saveQuietly();

        $person->set('pa_code', '40123');
        $person->set('nivel', 'Gerencial');
        $person->set('area', 'Recursos Humanos');

        return $person;
    }
}
