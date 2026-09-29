<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Services\ReportRefreshService;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Reporting\CotizacionDefinition;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * Quotes were imported but never flattened, so "which companies asked for a proposal with
 * facilitator X" and "who asked about theme Y" had nowhere to run.
 *
 * The approval flag is the other half: `LeadMapper::stageForStatusName()` maps a vocabulary this
 * install does not use — it expects GANADA / PERDIDA while the data says Aprobada / Rechazada —
 * so every quote sits in the default pipeline stage and the stage cannot distinguish an approved
 * proposal from a rejected one. The flat table reads the legacy status instead.
 *
 * The reporting connection is in no test's transact list, so rows are cleaned up explicitly.
 */
class CotizacionRefreshTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private CotizacionDefinition $definition;
    private ReportRefreshService $refresh;

    /** @var array<int, Lead> */
    private array $leads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->definition = new CotizacionDefinition($this->kanvasApp->getId());
        $this->refresh = new ReportRefreshService();

        new ReportSchemaService()->sync($this->definition, $this->kanvasApp->getId());
    }

    protected function tearDown(): void
    {
        // Raw deletes: Lead::delete() runs the workflow hooks, which register error/exception
        // handlers PHPUnit then reports as leaked out of the test.
        foreach ($this->leads as $lead) {
            $this->flatRows()->where('cotizacion_id', $lead->getId())->delete();
            DB::connection('ecosystem')->table('apps_custom_fields')
                ->where('model_name', Lead::class)
                ->where('entity_id', $lead->getId())
                ->delete();
            DB::connection('crm')->table('leads')->where('id', $lead->getId())->delete();
        }

        parent::tearDown();
    }

    public function test_an_approved_quote_flattens_with_its_facilitators_and_themes(): void
    {
        $lead = $this->seedQuote('Aprobada', [
            'facilitadores' => ['TERESA BARÓ'],
            'temas' => ['COACHING'],
            'eventos_solicitados' => ['COACHING EJECUTIVO'],
        ], monto: '15000.00');

        $written = $this->refresh->refresh(
            $this->definition,
            $this->kanvasApp,
            $this->company,
            [(int) $lead->getId()]
        );

        $this->assertSame(1, $written);

        $row = $this->flatRow($lead);

        $this->assertSame('Aprobada', $row->estatus);
        $this->assertSame(1, (int) $row->es_aprobada);
        $this->assertSame('15000.00', (string) $row->monto);
        $this->assertSame(['TERESA BARÓ'], json_decode((string) $row->facilitadores, true));
        $this->assertSame(['COACHING'], json_decode((string) $row->temas, true));
    }

    /**
     * The catalog mixes casing and gender across the two approved states — "Aprobada" and
     * "APROBADO PLAN" — so an exact-match list would silently drop the 12 plan approvals.
     */
    public function test_the_plan_approval_spelling_also_counts_as_approved(): void
    {
        $lead = $this->seedQuote('APROBADO PLAN', []);

        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $lead->getId()]);

        $this->assertSame(1, (int) $this->flatRow($lead)->es_aprobada);
    }

    public function test_a_rejected_quote_is_not_approved(): void
    {
        $lead = $this->seedQuote('Rechazada', []);

        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $lead->getId()]);

        $row = $this->flatRow($lead);

        $this->assertSame('Rechazada', $row->estatus);
        $this->assertSame(0, (int) $row->es_aprobada);
    }

    /**
     * The pipeline is shared with whatever else the tenant creates; a lead that is not a SIPGO
     * quote must not appear as a proposal.
     */
    public function test_a_lead_without_a_legacy_quote_id_is_not_a_quote(): void
    {
        $lead = $this->seedQuote('Aprobada', [], withLegacyId: false);

        $this->refresh->refresh($this->definition, $this->kanvasApp, $this->company, [(int) $lead->getId()]);

        $this->assertSame(0, $this->flatRows()->where('cotizacion_id', $lead->getId())->count());
    }

    /**
     * @param array<string, list<string>> $relations
     */
    private function seedQuote(
        string $estatus,
        array $relations,
        string $monto = '0.00',
        bool $withLegacyId = true
    ): Lead {
        $lead = new Lead();
        $lead->apps_id = $this->kanvasApp->getId();
        $lead->companies_id = $this->company->getId();
        $lead->companies_branches_id = 0;
        $lead->users_id = static::$cachedUser->getId();
        $lead->leads_owner_id = static::$cachedUser->getId();
        $lead->people_id = 0;
        $lead->title = 'Quote fixture ' . uniqid();
        $lead->pipeline_id = 0;
        $lead->pipeline_stage_id = 0;
        $lead->leads_status_id = 0;
        $lead->disableWorkflows();
        // saveQuietly() skips UuidTrait's creating hook — Lead has a NOT NULL uuid.
        $lead->generateUuidIfMissing()->saveQuietly();

        if ($withLegacyId) {
            $lead->set(CustomFieldEnum::INTRAS_QUOTE_ID->value, random_int(900000, 999999));
        }

        $lead->set('estatus', $estatus);
        $lead->set('quote_amount', $monto);

        foreach (['facilitadores', 'eventos_solicitados', 'temas'] as $key) {
            $lead->set($key, $relations[$key] ?? []);
        }

        $this->leads[] = $lead;

        return $lead;
    }

    private function flatRow(Lead $lead): object
    {
        return $this->flatRows()->where('cotizacion_id', $lead->getId())->first();
    }

    private function flatRows(): \Illuminate\Database\Query\Builder
    {
        $schema = new ReportSchemaService();

        return $schema->connection()->table($schema->tableFor($this->definition, $this->kanvasApp->getId()));
    }
}
