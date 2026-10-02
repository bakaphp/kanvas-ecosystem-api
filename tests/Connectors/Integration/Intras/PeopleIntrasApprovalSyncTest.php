<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\PushPeopleToIntrasAction;
use Kanvas\Connectors\Intras\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
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

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'social'];

    private const string SIPGO_DATABASE = 'intras_people_sync_test';
    private const int PARTICIPANT_ID = 501;

    private array $previousSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createSipgoSchema();
        $this->pointIntrasAtScratchDatabase();
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->previousSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }

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
        $this->addContact($people, ContactTypeEnum::CELLPHONE, '8295550000', 1);

        $this->assertSame([
            'participant' => [
                'first_name' => ['from' => 'Ana', 'to' => 'Anabel'],
                'full_name' => ['from' => 'Ana Pérez', 'to' => 'Anabel Pérez'],
                'position' => ['from' => 'Gerente', 'to' => 'Directora'],
            ],
            'custom_fields' => [
                'email_oficina' => ['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'],
                'celular_2' => ['from' => null, 'to' => '8295550000'],
            ],
        ], new DiffPeopleWithIntrasAction($people)->execute());
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

        new PushPeopleToIntrasAction($people, [
            'participant' => ['first_name' => ['from' => 'Ana', 'to' => 'Anabel']],
            'custom_fields' => [
                'email_oficina' => ['from' => 'Ana@Example.com', 'to' => 'anabel@example.com'],
                'celular_2' => ['from' => null, 'to' => '8295550000'],
            ],
        ])->execute();

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
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $this->artisan('kanvas:approvals:seed-people-policy', [
            'apps_id' => $app->getId(),
            'company_id' => $company->getId(),
            '--connector' => 'intras',
        ])->assertSuccessful();

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
            ApprovalRequest::find($second['approval_request_id'])->payload['changes']['participant']['last_name']
        );
    }

    public function test_a_numerically_equal_but_different_value_is_not_mistaken_for_the_pending_one(): void
    {
        $app = app(Apps::class);
        $this->artisan('kanvas:approvals:seed-people-policy', [
            'apps_id' => $app->getId(),
            'company_id' => static::$cachedUser->getCurrentCompany()->getId(),
            '--connector' => 'intras',
        ])->assertSuccessful();

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
            'payload' => ['changes' => ['participant' => ['first_name' => ['from' => 'Ana', 'to' => 'X']]]],
            'status' => ApprovalStatusEnum::APPROVED,
            'current_step' => 1,
        ]);

        $result = $this->activity(PushApprovedPeopleActivity::class)->execute($request, $app, []);

        $this->assertFalse($result['pushed']);
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

        $this->addContact($people, ContactTypeEnum::EMAIL, 'ana@example.com', 0);
        $this->addContact($people, ContactTypeEnum::CELLPHONE, '8095551234', 0);

        return $people;
    }

    private function addContact(People $people, ContactTypeEnum $type, string $value, int $weight): void
    {
        Contact::create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $this->contactTypeId($type),
            'value' => $value,
            'weight' => $weight,
        ]);
    }

    private function contactTypeId(ContactTypeEnum $type): int
    {
        return ContactType::getByName($type->getName())->getId();
    }

    private function sipgo(): ConnectionInterface
    {
        return new Client(app(Apps::class))->getConnection();
    }

    private function pointIntrasAtScratchDatabase(): void
    {
        $app = app(Apps::class);
        $mysql = config('database.connections.mysql');

        $settings = [
            ConfigurationEnum::INTRAS_DB_HOST->value => $mysql['write']['host'][0],
            ConfigurationEnum::INTRAS_DB_PORT->value => (string) $mysql['port'],
            ConfigurationEnum::INTRAS_DB_DATABASE->value => self::SIPGO_DATABASE,
            ConfigurationEnum::INTRAS_DB_USERNAME->value => $mysql['username'],
            ConfigurationEnum::INTRAS_DB_PASSWORD->value => $mysql['password'],
        ];

        foreach ($settings as $key => $value) {
            $this->previousSettings[$key] = $app->get($key);
            $app->set($key, $value);
        }
    }

    /**
     * Through its own connection: DDL on `mysql` would implicitly commit the test's transaction.
     */
    private function createSipgoSchema(): void
    {
        Config::set('database.connections.intras_fixture', [
            ...config('database.connections.mysql'),
            'database' => '',
        ]);

        $fixture = DB::connection('intras_fixture');
        $db = self::SIPGO_DATABASE;

        $fixture->statement("CREATE DATABASE IF NOT EXISTS `{$db}`");
        $fixture->statement("DROP TABLE IF EXISTS `{$db}`.participants, `{$db}`.custom_fields, `{$db}`.participants_custom_fields");
        $fixture->statement("CREATE TABLE `{$db}`.participants (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            first_name VARCHAR(64) NOT NULL,
            last_name VARCHAR(64) NOT NULL,
            full_name VARCHAR(130) NULL,
            position VARCHAR(64) NULL,
            identification VARCHAR(16) NULL,
            updated_at DATETIME NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.custom_fields (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            modules_id INT UNSIGNED NOT NULL,
            name VARCHAR(64) NOT NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.participants_custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            participants_id INT UNSIGNED NOT NULL,
            custom_fields_id INT UNSIGNED NOT NULL,
            value VARCHAR(64) NULL,
            KEY idx3 (participants_id, custom_fields_id)
        )");

        $fixture->table("{$db}.participants")->insert([
            'id' => self::PARTICIPANT_ID,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'full_name' => 'Ana Pérez',
            'position' => 'Gerente',
            'identification' => '00112345678',
        ]);

        $fixture->table("{$db}.custom_fields")->insert([
            ['id' => 42, 'modules_id' => 3, 'name' => 'email_oficina'],
            ['id' => 56, 'modules_id' => 4, 'name' => 'sexo'],
            ['id' => 65, 'modules_id' => 4, 'name' => 'celular_1'],
            ['id' => 66, 'modules_id' => 4, 'name' => 'celular_2'],
            ['id' => 67, 'modules_id' => 4, 'name' => 'email_oficina'],
        ]);

        $fixture->table("{$db}.participants_custom_fields")->insert([
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 56, 'value' => 'F'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 65, 'value' => '809-555-1234'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 67, 'value' => 'Ana@Example.com'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 67, 'value' => 'Ana@Example.com'],
        ]);
    }
}
