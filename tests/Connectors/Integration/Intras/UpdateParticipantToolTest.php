<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Neuron\Tools\UpdateParticipantTool;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\WorkflowEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\UsesSipgoScratchSchema;
use Tests\Intelligence\Agents\Tools\Support\RecordingWorkflowActivity;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncPullRules;

/**
 * The scratch SIPGO schema supplies the `participants_levels` catalog the nivel check reads.
 */
#[Group('serial')]
final class UpdateParticipantToolTest extends TestCase
{
    use BuildsSyncPullRules;
    use DatabaseTransactions;
    use UsesSipgoScratchSchema;

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

        RecordingWorkflowActivity::$entityIds = [];
        $this->createSyncRule(
            app: $this->kanvasApp,
            event: WorkflowEnum::AGENT_UPDATED->value,
            modelClass: People::class,
            activityClass: RecordingWorkflowActivity::class,
            actionName: 'Record intras participant edit ' . uniqid(),
        );
    }

    protected function tearDown(): void
    {
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function testACorrectionByPaCodeWritesTheFieldsThePushReadsAndAnnouncesOnce(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $person = $this->participant(
            $legacyId,
            'johanna',
            'rodriguez',
            position: 'Encargada de RRHH'
        );
        $person->addContact(ContactTypeEnum::EMAIL->getName(), 'old@parval.com.do');

        $result = $this->tool()(
            referencia: 'PA' . $legacyId,
            nombre: 'Johanna',
            cargo: 'Subgerente de Negocios',
            nivel: 'Gerente',
            email_oficina: 'jorodriguez@parval.com.do',
        );

        $this->assertSame('ok', $result['outcome'], json_encode($result));
        $this->assertSame('PA' . $legacyId, $result['pa']);
        $this->assertTrue($result['pendiente_aprobacion']);
        $this->assertSame(['nombre', 'cargo', 'nivel', 'email_oficina'], array_keys($result['cambios']));
        $this->assertSame(['de' => 'Encargada de RRHH', 'a' => 'Subgerente de Negocios'], $result['cambios']['cargo']);
        $this->assertStringContainsString('PENDIENTES DE APROBACIÓN', $result['note']);

        $person->refresh();
        $this->assertSame('Johanna', $person->firstname);
        $this->assertSame('Subgerente de Negocios', $person->get('position'));
        $this->assertSame('Gerente', $person->get('nivel'));
        $this->assertSame(
            ['jorodriguez@parval.com.do'],
            $person->contacts()->where('is_deleted', 0)->pluck('value')->all(),
            'the office email slot is replaced, not doubled'
        );
        $this->assertSame([$person->getId()], RecordingWorkflowActivity::$entityIds, 'AGENT_UPDATED fired exactly once');
    }

    public function testAnUnknownCatalogNameIsRefusedBeforeAnythingIsWritten(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $person = $this->participant($legacyId, 'Laura', 'Ortega', position: 'Analista');

        $result = $this->tool()(referencia: 'PA' . $legacyId, cargo: 'Directora', nivel: 'Director Galáctico');

        $this->assertSame('invalid_args', $result['outcome'], json_encode($result));
        $this->assertStringContainsString('Director Galáctico', $result['error'] ?? $result['note']);
        $this->assertSame('Analista', $person->refresh()->get('position'), 'the valid field is not written either');
        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    public function testACallThatChangesNothingOpensNoApproval(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $this->participant($legacyId, 'Laura', 'Ortega', position: 'Analista');

        $result = $this->tool()(referencia: 'PA' . $legacyId, cargo: 'Analista', nombre: 'Laura');

        $this->assertSame('noop', $result['outcome'], json_encode($result));
        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    public function testAnotherCompanysParticipantAndABadReferenceTalkBack(): void
    {
        $otherCompany = Companies::factory()->create(['users_id' => static::$cachedUser->getId()]);
        $legacyId = random_int(9_000_000, 9_999_999);
        $this->participant(
            $legacyId,
            'Ajena',
            'Persona',
            company: $otherCompany
        );

        $foreign = $this->tool()(referencia: 'PA' . $legacyId, cargo: 'X');
        $this->assertSame('not_found', $foreign['outcome'], json_encode($foreign));

        $wrongKind = $this->tool()(referencia: 'VE7160', cargo: 'X');
        $this->assertSame('invalid_args', $wrongKind['outcome']);

        $nothing = $this->tool()(referencia: 'PA' . $legacyId);
        $this->assertSame('invalid_args', $nothing['outcome']);

        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    private function tool(): UpdateParticipantTool
    {
        return new UpdateParticipantTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function participant(
        int $legacyId,
        string $firstname,
        string $lastname,
        ?string $position = null,
        ?Companies $company = null
    ): People {
        $person = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(($company ?? $this->company)->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create(['firstname' => $firstname, 'lastname' => $lastname, 'name' => $firstname . ' ' . $lastname]);

        $person->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyId);

        if ($position !== null) {
            $person->set('position', $position);
        }

        return $person;
    }
}
