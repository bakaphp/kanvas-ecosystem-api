<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\PushToIntrasAction;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Mappers\FacilitatorMapper;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\UsesSipgoScratchSchema;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

#[Group('serial')]
final class FacilitatorIntrasApprovalSyncTest extends TestCase
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

    public function test_to_intras_maps_columns_contacts_and_catalog_names(): void
    {
        $people = $this->importedFacilitator();
        $facilitator = Facilitator::query()->where('people_id', $people->getId())->firstOrFail();
        $people->set('estatus', 'Inactivo');

        $desired = FacilitatorMapper::toIntras($people->refresh(), $facilitator);

        $this->assertSame([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'identification' => '00112345678',
            'cv' => 'Consultora senior',
        ], $desired['row']);
        $this->assertSame(['email' => 'ana@example.com'], $desired['custom_fields']);
        $this->assertSame(['facilitators_statuses_id' => ['table' => 'facilitators_statuses', 'name' => 'Inactivo']], $desired['catalog']);
    }

    public function test_cv_comes_from_the_facilitator_row(): void
    {
        $people = $this->importedFacilitator();
        Facilitator::query()->where('people_id', $people->getId())->update(['resume' => 'Consultora principal']);

        $items = new DiffPeopleWithIntrasAction($people)->execute();

        $this->assertSame([[
            'kind' => 'facilitator',
            'legacy_id' => self::FACILITATOR_ID,
            'changes' => ['row' => ['cv' => ['from' => 'Consultora senior', 'to' => 'Consultora principal']]],
        ]], $items);
    }

    public function test_a_person_who_is_both_opens_two_items_and_push_writes_both_tables(): void
    {
        $people = $this->importedFacilitator();
        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);
        $people->set('position', 'Gerente');
        $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->update(['value' => 'anabel@example.com']);

        $items = new DiffPeopleWithIntrasAction($people->refresh())->execute();

        $this->assertSame(['participant', 'facilitator'], array_column($items, 'kind'));
        $this->assertSame(['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'], $items[0]['changes']['custom_fields']['email_oficina']);
        $this->assertSame(['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'], $items[1]['changes']['custom_fields']['email']);

        new PushToIntrasAction($people, $items)->execute();

        $sipgo = $this->sipgo();
        $this->assertSame(
            ['anabel@example.com', 'anabel@example.com'],
            $sipgo->table('participants_custom_fields')->where('custom_fields_id', 67)->pluck('value')->all()
        );
        $this->assertSame(
            ['anabel@example.com'],
            $sipgo->table('facilitators_custom_fields')->where('custom_fields_id', 80)->pluck('value')->all()
        );
    }

    public function test_a_facilitator_only_person_opens_a_request_through_the_activity(): void
    {
        $this->seedPeoplePolicy();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
        $people = $this->importedFacilitator();
        $people->set('estatus', 'Inactivo');

        $result = $this->runnableActivity(RequestPeopleApprovalActivity::class)->execute($people->refresh(), app(Apps::class), []);

        $this->assertTrue($result['requested'] ?? false, json_encode($result));
        $items = $people->pendingApproval(PeopleIntrasSyncApprovalTypeEnum::UPDATE->value)->payload['items'];
        $this->assertSame('facilitator', $items[0]['kind']);
        $this->assertSame('2', $items[0]['changes']['row']['facilitators_statuses_id']['to']);
    }

    public function test_a_dual_person_pushes_shared_fields_only_as_a_participant(): void
    {
        $people = $this->importedFacilitator();
        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);
        $people->set('position', 'Gerente');
        $people->set('estatus', 'Inactivo');
        $people->set('pais', 'República Dominicana');

        $items = new DiffPeopleWithIntrasAction($people->refresh())->execute();

        $this->assertSame(['participant'], array_column($items, 'kind'));
        $this->assertSame('2', $items[0]['changes']['row']['participants_statuses_id']['to']);
    }

    public function test_a_dual_push_writes_nothing_when_one_item_fails(): void
    {
        $people = $this->importedFacilitator();
        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);

        try {
            new PushToIntrasAction($people->refresh(), [
                ['kind' => 'participant', 'legacy_id' => self::PARTICIPANT_ID, 'changes' => ['row' => ['first_name' => ['from' => 'Ana', 'to' => 'Anabel']]]],
                ['kind' => 'facilitator', 'legacy_id' => self::FACILITATOR_ID, 'changes' => ['custom_fields' => ['no_such_field' => ['from' => null, 'to' => 'x']]]],
            ])->execute();
            $this->fail('Expected the unknown custom field to refuse the push.');
        } catch (ValidationException) {
        }

        $this->assertSame('Ana', $this->sipgo()->table('participants')->where('id', self::PARTICIPANT_ID)->value('first_name'));
    }

    public function test_a_concurrent_request_is_refused_while_the_lock_is_held(): void
    {
        $this->seedPeoplePolicy();
        $this->registerInternalIntegration(IntegrationsEnum::INTRAS);
        $people = $this->importedFacilitator();
        $people->set('estatus', 'Inactivo');

        $lock = Cache::lock(RequestPeopleApprovalActivity::lockKey($people), 30);
        $this->assertTrue($lock->get());

        try {
            $result = $this->runnableActivity(RequestPeopleApprovalActivity::class)->execute($people->refresh(), app(Apps::class), []);
        } finally {
            $lock->release();
        }

        $this->assertFalse($result['requested']);
        $this->assertSame('another request for this record is being opened', $result['reason']);
        $this->assertNull($people->pendingApproval());
    }

    /**
     * A People as the facilitator pull leaves it: the Facilitator row holds identification and CV,
     * the People holds the link, contacts and catalog names.
     */
    private function importedFacilitator(): People
    {
        $user = static::$cachedUser;
        $app = app(Apps::class);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Pérez']);

        $people->set(CustomFieldEnum::INTRAS_FACILITATOR_ID->value, self::FACILITATOR_ID);
        $people->set('estatus', 'Activo');

        $this->addContact($people, ContactTypeEnum::EMAIL, 'ana@example.com');

        Facilitator::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'people_id' => $people->getId(),
            'slug' => 'ana-perez-' . $people->getId(),
            'identification' => '00112345678',
            'resume' => 'Consultora senior',
        ]);

        return $people;
    }
}
