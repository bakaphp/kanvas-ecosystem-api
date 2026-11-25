<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Models as BouncerModels;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class IntegrationImportGraphQLTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;
    use ImportsTestSetup;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private const string RUN_FIELDS = 'entity status processed created total errors { id error } queued_at';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testImportablesComeWithTheConnectedIntegration(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);

        IntegrationImport::create([
            'apps_id' => $row->apps_id,
            'companies_id' => $row->companies_id,
            'integrations_id' => $row->integrations_id,
            'integrations_companies_id' => $row->getId(),
            'users_id' => auth()->id(),
            'entity' => 'plain',
            'status' => IntegrationImportStatusEnum::COMPLETED,
        ]);

        $response = $this->graphQL('
            query($id: Mixed) {
                integrations(where: {column: ID, operator: EQ, value: $id}) {
                    data {
                        integrations {
                            id
                            importables {
                                entity
                                label
                                options { name type required multiple }
                                last_import { status }
                            }
                        }
                    }
                }
            }
        ', ['id' => $this->integration->getId()]);

        $importables = $response->json('data.integrations.data.0.integrations.0.importables');

        $this->assertSame(['chunky', 'plain', 'whole'], array_column($importables, 'entity'));
        $this->assertSame(
            [
                ['name' => 'region_ids', 'type' => 'choices', 'required' => true, 'multiple' => true],
                ['name' => 'flag', 'type' => 'boolean', 'required' => false, 'multiple' => false],
            ],
            $importables[0]['options']
        );
        $this->assertNull($importables[0]['last_import']);
        $this->assertSame('COMPLETED', $importables[1]['last_import']['status']);
    }

    public function testImportablesAreEmptyWhenTheIntegrationIsNotActive(): void
    {
        $row = $this->createIntegrationRows(statusSlug: 'failed');
        $this->moveToCurrentCompany($row);

        $response = $this->graphQL('
            query($id: Mixed) {
                integrations(where: {column: ID, operator: EQ, value: $id}) {
                    data { integrations { importables { entity } } }
                }
            }
        ', ['id' => $this->integration->getId()]);

        $this->assertSame([], $response->json('data.integrations.data.0.integrations.0.importables'));

        $this->importMutation($row, 'plain')
            ->assertJsonPath('errors.0.message', 'This integration is not active.');
    }

    public function testImportQueuesAndStatusReportsIt(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);

        $queued = $this->importMutation($row, 'plain');

        $this->assertSame('QUEUED', $queued->json('data.integrationImport.status'));

        $status = $this->graphQL('
            query($id: ID!) {
                integrationImportStatus(integration_company_id: $id, entity: "plain") { ' . self::RUN_FIELDS . ' }
            }
        ', ['id' => $row->getId()]);

        $this->assertSame('plain', $status->json('data.integrationImportStatus.entity'));
        $this->assertSame('QUEUED', $status->json('data.integrationImportStatus.status'));
        $this->assertSame([], $status->json('data.integrationImportStatus.errors'));
    }

    public function testStatusIsNullWhenNothingWasImported(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);

        $status = $this->graphQL('
            query($id: ID!) {
                integrationImportStatus(integration_company_id: $id, entity: "plain") { entity }
            }
        ', ['id' => $row->getId()]);

        $status->assertJsonPath('data.integrationImportStatus', null);
        $this->assertNull($status->json('errors'));
    }

    public function testChoicesComeFromTheImporter(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);

        $response = $this->choicesQuery($row, 'region_ids');

        $this->assertSame(
            [
                ['value' => '1', 'label' => 'One', 'suggested' => true],
                ['value' => '2', 'label' => 'Two', 'suggested' => false],
            ],
            $response->json('data.integrationImportOptionChoices')
        );
    }

    public function testChoicesForANonChoicesOptionIsRefused(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);

        $this->choicesQuery($row, 'flag')
            ->assertJsonPath('errors.0.message', 'The option flag has no choices.');
    }

    public function testAnotherAppsIntegrationIsNotFound(): void
    {
        $row = $this->createIntegrationRows();
        $this->moveToCurrentCompany($row);
        $row->apps_id = $row->apps_id + 900000;
        $row->save();

        $this->importMutation($row, 'plain')
            ->assertJsonPath('errors.0.message', 'Integration not found.');
        $this->choicesQuery($row, 'region_ids')
            ->assertJsonPath('errors.0.message', 'Integration not found.');
    }

    public function testAnotherCompanysIntegrationIsNotFound(): void
    {
        $row = $this->createIntegrationRows();
        $admin = auth()->user();
        $outsider = $this->createUser();
        BouncerModels::query('assigned_roles')
            ->where('entity_id', $outsider->getId())
            ->where('entity_type', new Users()->getMorphClass())
            ->delete();
        Bouncer::refresh();
        $this->assertFalse($outsider->isAdmin());
        $this->assertNotSame($admin->getId(), $outsider->getId());

        $this->actingAs($outsider, 'api');

        $this->importMutation($row, 'plain')
            ->assertJsonPath('errors.0.message', 'Integration not found.');
        $this->choicesQuery($row, 'region_ids')
            ->assertJsonPath('errors.0.message', 'Integration not found.');
    }

    public function testChoicesAreRefusedWhenTheIntegrationIsNotActive(): void
    {
        $row = $this->createIntegrationRows(statusSlug: 'failed');
        $this->moveToCurrentCompany($row);

        $this->choicesQuery($row, 'region_ids')
            ->assertJsonPath('errors.0.message', 'This integration is not active.');
    }

    public function testAMissingIntegrationIsNotFound(): void
    {
        $row = $this->createIntegrationRows();
        $row->id = 99999999;

        $this->importMutation($row, 'plain')
            ->assertJsonPath('errors.0.message', 'Integration not found.');
    }

    private function moveToCurrentCompany(IntegrationsCompany $row): void
    {
        $row->companies_id = auth()->user()->getCurrentCompany()->getId();
        $row->save();
    }

    private function importMutation(IntegrationsCompany $row, string $entity)
    {
        return $this->graphQL('
            mutation($id: ID!, $entity: String!) {
                integrationImport(integration_company_id: $id, entity: $entity) { ' . self::RUN_FIELDS . ' }
            }
        ', ['id' => $row->getId(), 'entity' => $entity]);
    }

    private function choicesQuery(IntegrationsCompany $row, string $option)
    {
        return $this->graphQL('
            query($id: ID!, $option: String!) {
                integrationImportOptionChoices(integration_company_id: $id, entity: "chunky", option: $option) {
                    value label suggested
                }
            }
        ', ['id' => $row->getId(), 'option' => $option]);
    }
}
