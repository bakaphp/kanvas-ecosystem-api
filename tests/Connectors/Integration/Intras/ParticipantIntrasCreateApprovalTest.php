<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\UsesSipgoScratchSchema;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

#[Group('serial')]
final class ParticipantIntrasCreateApprovalTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersInternalIntegration;
    use UsesSipgoScratchSchema;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'social', 'event'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createSipgoSchema();
        $this->pointIntrasAtScratchDatabase();
        $this->seedPeoplePolicy();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
    }

    protected function tearDown(): void
    {
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function test_an_unlinked_participant_opens_a_create_request_with_defaults(): void
    {
        $people = $this->unlinkedParticipant();

        $result = $this->runnableActivity(RequestPeopleApprovalActivity::class)->execute($people, app(Apps::class), []);

        $this->assertTrue($result['requested'] ?? false, json_encode($result));
        $request = $people->pendingApproval(PeopleIntrasSyncApprovalTypeEnum::CREATE->value);
        $this->assertNotNull($request);
        $item = $request->payload['items'][0];

        $this->assertSame('participant', $item['kind']);
        $this->assertNull($item['legacy_id']);
        $this->assertEquals([
            'first_name' => ['from' => null, 'to' => 'Luis'],
            'last_name' => ['from' => null, 'to' => 'Nuevo'],
            'full_name' => ['from' => null, 'to' => 'Luis Nuevo'],
            'companies_id' => ['from' => null, 'to' => (string) self::COMPANY_ID],
            'participants_statuses_id' => ['from' => null, 'to' => '1', 'label' => ['from' => null, 'to' => 'Activo']],
        ], $item['changes']['row']);
        $this->assertEquals(['email_oficina' => ['from' => null, 'to' => 'luis@example.com']], $item['changes']['custom_fields']);
    }

    public function test_a_person_whose_organization_is_not_in_sipgo_fails_the_workflow(): void
    {
        $people = $this->unlinkedParticipant(organizationInSipgo: false);

        $activity = $this->runnableActivity(RequestPeopleApprovalActivity::class);
        $result = $activity->execute($people, app(Apps::class), []);

        $this->assertFalse($result['requested']);
        $this->assertStringContainsString('not in SIPGO', $result['reason']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertNull($people->pendingApproval());
    }

    public function test_a_person_matching_an_existing_sipgo_participant_is_linked_instead(): void
    {
        $people = $this->unlinkedParticipant(email: 'ana@example.com');

        $result = $this->runnableActivity(RequestPeopleApprovalActivity::class)->execute($people, app(Apps::class), []);

        $this->assertSame(self::PARTICIPANT_ID, (int) $people->refresh()->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value));
        $this->assertNull($people->pendingApproval(PeopleIntrasSyncApprovalTypeEnum::CREATE->value));
        $this->assertTrue($result['requested'], json_encode($result));
        $this->assertNotNull($people->pendingApproval(PeopleIntrasSyncApprovalTypeEnum::UPDATE->value));
    }

    public function test_a_non_participant_person_is_skipped(): void
    {
        $people = $this->unlinkedParticipant();
        $people->people_types_id = null;
        $people->saveOrFail();

        $result = $this->runnableActivity(RequestPeopleApprovalActivity::class)->execute($people->refresh(), app(Apps::class), []);

        $this->assertFalse($result['requested']);
        $this->assertNull($people->pendingApproval());
    }

    public function test_push_inserts_the_participant_and_links_the_person(): void
    {
        $people = $this->unlinkedParticipant();
        $request = $this->approvedCreateRequest($people);

        $result = $this->runnableActivity(PushApprovedPeopleActivity::class)->execute($request, app(Apps::class), []);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $row = $this->sipgo()->table('participants')->where('first_name', 'Luis')->first();
        $this->assertNotNull($row);
        $this->assertSame(self::COMPANY_ID, (int) $row->companies_id);
        $this->assertSame(1, (int) $row->participants_statuses_id);
        $this->assertNotNull($row->created_at);
        $this->assertSame(['luis@example.com'], $this->sipgo()->table('participants_custom_fields')->where('participants_id', $row->id)->where('custom_fields_id', 67)->pluck('value')->all());
        $this->assertSame((int) $row->id, (int) $people->refresh()->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value));
    }

    public function test_push_refuses_a_create_for_a_person_already_linked(): void
    {
        $people = $this->unlinkedParticipant();
        $request = $this->approvedCreateRequest($people);
        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);

        $result = $this->runnableActivity(PushApprovedPeopleActivity::class)->execute($request, app(Apps::class), []);

        $this->assertArrayHasKey('error', $result, json_encode($result));
        $this->assertSame(0, $this->sipgo()->table('participants')->where('first_name', 'Luis')->count());
    }

    private function approvedCreateRequest(People $people): ApprovalRequest
    {
        $app = app(Apps::class);

        return ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $people->companies_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            'entity_id' => $people->getId(),
            'approval_type' => PeopleIntrasSyncApprovalTypeEnum::CREATE->value,
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => ['people_id' => $people->getId(), 'items' => [[
                'kind' => 'participant',
                'legacy_id' => null,
                'changes' => [
                    'row' => [
                        'first_name' => ['from' => null, 'to' => 'Luis'],
                        'last_name' => ['from' => null, 'to' => 'Nuevo'],
                        'companies_id' => ['from' => null, 'to' => (string) self::COMPANY_ID],
                        'participants_statuses_id' => ['from' => null, 'to' => '1', 'label' => ['from' => null, 'to' => 'Activo']],
                    ],
                    'custom_fields' => ['email_oficina' => ['from' => null, 'to' => 'luis@example.com']],
                ],
            ]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);
    }

    /**
     * A participant created in Control Center: typed Participant, in a SIPGO-linked organization,
     * not yet in SIPGO.
     */
    private function unlinkedParticipant(bool $organizationInSipgo = true, string $email = 'luis@example.com'): People
    {
        $user = static::$cachedUser;
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $type = PeopleType::firstOrCreate([
            'name' => ParticipantMapper::PARTICIPANT_PEOPLE_TYPE,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ], ['users_id' => $user->getId(), 'is_default' => true]);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Luis', 'lastname' => 'Nuevo', 'people_types_id' => $type->getId()]);

        $this->addContact($people, ContactTypeEnum::EMAIL, $email);

        $organization = Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'ACME SRL ' . $people->getId(),
        ]);

        if ($organizationInSipgo) {
            $organization->set(CustomFieldEnum::INTRAS_COMPANY_ID->value, self::COMPANY_ID);
        }

        OrganizationPeople::addPeopleToOrganization($organization, $people);

        return $people->refresh();
    }
}
