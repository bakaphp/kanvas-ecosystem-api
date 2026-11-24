<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\PushToIntrasAction;
use Kanvas\Connectors\Intras\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Connectors\Intras\Support\SipgoCatalog;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Tests\Connectors\Integration\Intras\Concerns\UsesSipgoScratchSchema;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

/**
 * Runs against a scratch SIPGO schema on the test MySQL server, shaped like the real tables
 * (participants_custom_fields has no unique key; custom_fields names repeat across modules).
 * Serial because the Intras connection settings are app settings in shared Redis.
 */
#[Group('serial')]
final class PeopleIntrasApprovalSyncTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function test_a_person_matching_sipgo_has_nothing_to_push(): void
    {
        $people = $this->importedPeople();

        $this->assertSame([], new DiffPeopleWithIntrasAction($people)->execute());
    }

    public function test_edits_become_a_from_to_change_set_without_reshuffling_contact_slots(): void
    {
        $people = $this->importedPeople();
        $people->firstname = 'Anabel';
        $people->saveOrFail();
        $people->set('position', 'Directora');

        $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->update(['value' => 'anabel@example.com']);
        $this->addContact(
            $people,
            ContactTypeEnum::CELLPHONE,
            '8295550000',
            1
        );

        $this->assertSame([[
            'kind' => 'participant',
            'legacy_id' => self::PARTICIPANT_ID,
            'changes' => [
                'row' => [
                    'first_name' => ['from' => 'Ana', 'to' => 'Anabel'],
                    'full_name' => ['from' => 'Ana Pérez', 'to' => 'Anabel Pérez'],
                    'position' => ['from' => 'Gerente', 'to' => 'Directora'],
                ],
                'custom_fields' => [
                    'email_oficina' => ['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'],
                    'celular_2' => ['from' => null, 'to' => '8295550000'],
                ],
            ],
        ]], new DiffPeopleWithIntrasAction($people)->execute());
    }

    public function test_to_intras_emits_catalog_names_and_department(): void
    {
        $people = $this->importedPeople();
        $people->set('nivel', 'Gerente');
        $people->set('department', 'Ventas');

        $desired = ParticipantMapper::toIntras($people->refresh());

        $this->assertSame('Ventas', $desired['row']['departments_id']);
        $this->assertSame(['table' => 'participants_levels', 'name' => 'Gerente'], $desired['catalog']['participants_levels_id']);
    }

    public function test_a_level_change_resolves_to_the_catalog_id_with_labels(): void
    {
        $people = $this->importedPeople();
        $people->set('nivel', 'gerente ');

        $items = new DiffPeopleWithIntrasAction($people->refresh())->execute();

        $this->assertSame([
            'participants_levels_id' => [
                'from' => '4',
                'to' => '7',
                'label' => ['from' => 'Coordinador', 'to' => 'Gerente'],
            ],
        ], $items[0]['changes']['row']);
    }

    public function test_an_unknown_catalog_name_refuses(): void
    {
        $people = $this->importedPeople();
        $people->set('nivel', 'Gerentee');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("participants_levels: no row named 'Gerentee'");

        new DiffPeopleWithIntrasAction($people->refresh())->execute();
    }

    public function test_an_ambiguous_name_already_held_is_not_a_change(): void
    {
        $catalog = new SipgoCatalog(new Client(app(Apps::class)));

        $this->assertSame('Santiago', $catalog->nameFor('cities', 10));
        $this->assertNull($catalog->nameFor('cities', null));

        try {
            $catalog->idFor('cities', 'santiago');
            $this->fail('Expected an ambiguous name to refuse.');
        } catch (ValidationException $e) {
            $this->assertSame("SIPGO cities: 'santiago' matches 2 rows", $e->getMessage());
        }

        $this->assertSame(7, $catalog->idFor('participants_levels', ' GERENTE'));
    }

    public function test_a_contact_deleted_in_kanvas_is_not_blanked_in_sipgo(): void
    {
        $people = $this->importedPeople();
        $people->contacts()->delete();

        $this->assertSame([], new DiffPeopleWithIntrasAction($people)->execute());
    }

    public function test_push_writes_the_participant_and_every_duplicate_custom_field_row_under_the_participant_module(): void
    {
        $people = $this->importedPeople();

        new PushToIntrasAction($people, [[
            'kind' => 'participant',
            'legacy_id' => self::PARTICIPANT_ID,
            'changes' => [
                'row' => ['first_name' => ['from' => 'Ana', 'to' => 'Anabel']],
                'custom_fields' => [
                    'email_oficina' => ['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'],
                    'celular_2' => ['from' => null, 'to' => '8295550000'],
                ],
            ],
        ]])->execute();

        $sipgo = $this->sipgo();
        $participant = $sipgo->table('participants')->where('id', self::PARTICIPANT_ID)->first();

        $this->assertSame('Anabel', $participant->first_name);
        $this->assertNotNull($participant->updated_at);
        $this->assertSame(
            ['anabel@example.com', 'anabel@example.com'],
            $sipgo->table('participants_custom_fields')->where('custom_fields_id', 67)->pluck('value')->all()
        );
        $this->assertSame(
            ['8295550000'],
            $sipgo->table('participants_custom_fields')->where('custom_fields_id', 66)->pluck('value')->all()
        );
        $this->assertSame(0, $sipgo->table('participants_custom_fields')->where('custom_fields_id', 42)->count());
    }

    public function test_request_opens_once_and_a_newer_edit_supersedes_it(): void
    {
        $this->seedPeoplePolicy();

        $people = $this->importedPeople();
        $people->firstname = 'Anabel';
        $people->saveOrFail();

        $first = $this->requestApproval($people);
        $this->assertTrue($first['requested']);

        $this->assertSame('already pending', $this->requestApproval($people)['reason']);

        $people->lastname = 'Gómez';
        $people->saveOrFail();

        $second = $this->requestApproval($people);

        $this->assertTrue($second['requested']);
        $this->assertSame(ApprovalStatusEnum::REJECTED, ApprovalRequest::find($first['approval_request_id'])->status);
        $this->assertEquals(
            ['from' => 'Pérez', 'to' => 'Gómez'],
            ApprovalRequest::find($second['approval_request_id'])->payload['items'][0]['changes']['row']['last_name']
        );
    }

    public function test_push_refuses_a_participant_sipgo_no_longer_has_and_writes_nothing(): void
    {
        $people = $this->importedPeople();
        $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->delete();

        try {
            new PushToIntrasAction($people, [[
                'kind' => 'participant',
                'legacy_id' => self::PARTICIPANT_ID,
                'changes' => ['custom_fields' => ['celular_2' => ['from' => null, 'to' => '8295550000']]],
            ]])->execute();
            $this->fail('Expected the push to refuse a missing participant.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $this->sipgo()->table('participants_custom_fields')->where('custom_fields_id', 66)->count());
    }

    public function test_push_rejects_an_item_whose_kind_the_person_is_not_linked_to(): void
    {
        $people = $this->importedPeople();

        try {
            new PushToIntrasAction($people, [[
                'kind' => 'facilitator',
                'legacy_id' => 9,
                'changes' => ['row' => ['first_name' => ['from' => 'Ana', 'to' => 'X']]],
            ]])->execute();
            $this->fail('Expected the push to refuse a kind the person is not linked to.');
        } catch (ValidationException) {
        }

        $this->assertSame('Ana', $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->value('first_name'));
    }

    public function test_a_numerically_equal_but_different_value_is_not_mistaken_for_the_pending_one(): void
    {
        $app = app(Apps::class);
        $this->seedPeoplePolicy();

        $people = $this->importedPeople();
        $people->set('identification', '0112345678');
        $this->assertTrue($this->requestApproval($people)['requested']);

        $people->set('identification', '112345678');

        $this->assertTrue($this->requestApproval($people)['requested']);
    }

    public function test_a_person_without_a_participant_id_is_skipped(): void
    {
        $people = $this->importedPeople();
        $people->del(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        $result = $this->activity(RequestPeopleApprovalActivity::class)->execute($people, app(Apps::class), []);

        $this->assertFalse($result['requested']);
    }

    public function test_push_activity_ignores_other_approval_types(): void
    {
        $people = $this->importedPeople();
        $app = app(Apps::class);

        $request = ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $people->companies_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            'entity_id' => $people->getId(),
            'approval_type' => 'approve_people',
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => ['items' => [['kind' => 'participant', 'legacy_id' => self::PARTICIPANT_ID, 'changes' => ['row' => ['first_name' => ['from' => 'Ana', 'to' => 'X']]]]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);

        $result = $this->activity(PushApprovedPeopleActivity::class)->execute($request, $app, []);

        $this->assertFalse($result['pushed']);
        $this->assertSame('Ana', $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->value('first_name'));
    }

    public function test_an_unknown_catalog_name_fails_the_workflow_without_opening_a_request(): void
    {
        $app = app(Apps::class);
        $this->seedPeoplePolicy();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);

        $people = $this->importedPeople();
        $people->set('nivel', 'Gerentee');

        $activity = $this->runnableActivity(RequestPeopleApprovalActivity::class);
        $result = $activity->execute($people->refresh(), $app, []);

        $this->assertFalse($result['requested']);
        $this->assertStringContainsString("participants_levels: no row named 'Gerentee'", $result['reason']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertNull($people->pendingApproval());
    }

    public function test_push_activity_writes_an_approved_items_payload(): void
    {
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
        $people = $this->importedPeople();
        $app = app(Apps::class);

        $request = ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $people->companies_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            'entity_id' => $people->getId(),
            'approval_type' => 'approve_people_intras_update',
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => ['people_id' => $people->getId(), 'items' => [[
                'kind' => 'participant',
                'legacy_id' => self::PARTICIPANT_ID,
                'changes' => [
                    'row' => ['participants_levels_id' => ['from' => '4', 'to' => '7', 'label' => ['from' => 'Coordinador', 'to' => 'Gerente']]],
                    'custom_fields' => ['celular_2' => ['from' => null, 'to' => '8295550000']],
                ],
            ]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);

        $result = $this->runnableActivity(PushApprovedPeopleActivity::class)->execute($request, $app, []);

        $this->assertArrayNotHasKey('error', $result, json_encode($result));
        $participant = $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->first();
        $this->assertSame(7, (int) $participant->participants_levels_id);
        $this->assertSame(['8295550000'], $this->sipgo()->table('participants_custom_fields')->where('custom_fields_id', 66)->pluck('value')->all());
    }

    public function test_push_activity_fails_an_empty_items_payload(): void
    {
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
        $people = $this->importedPeople();
        $app = app(Apps::class);

        $request = ApprovalRequest::create([
            'apps_id' => $app->getId(),
            'companies_id' => $people->companies_id,
            'system_modules_id' => SystemModulesRepository::getByModelName(People::class, $app)->getId(),
            'entity_id' => $people->getId(),
            'approval_type' => 'approve_people_intras_update',
            'origin' => ApprovalOriginEnum::SYSTEM,
            'payload' => ['changes' => ['participant' => ['first_name' => ['from' => 'Ana', 'to' => 'X']]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);

        $activity = $this->runnableActivity(PushApprovedPeopleActivity::class);
        $result = $activity->execute($request, $app, []);

        $this->assertFalse($result['pushed']);
        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertSame('Ana', $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->value('first_name'));
    }

    private function requestApproval(People $people): array
    {
        $method = new ReflectionMethod(RequestPeopleApprovalActivity::class, 'requestApproval');

        return $method->invoke($this->activity(RequestPeopleApprovalActivity::class), $people->refresh(), []);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function activity(string $class): object
    {
        return new ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    /**
     * A People as the participant pull leaves it: same names and fields, phones in Kanvas' 10-digit
     * form and emails lowercased while SIPGO keeps its own formatting.
     */
    private function importedPeople(): People
    {
        $user = static::$cachedUser;

        $people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Pérez']);

        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);
        $people->set('position', 'Gerente');
        $people->set('identification', '00112345678');
        $people->set('sexo', 'F');

        $this->addContact($people, ContactTypeEnum::EMAIL, 'ana@example.com');
        $this->addContact($people, ContactTypeEnum::CELLPHONE, '8095551234');

        return $people;
    }
}
