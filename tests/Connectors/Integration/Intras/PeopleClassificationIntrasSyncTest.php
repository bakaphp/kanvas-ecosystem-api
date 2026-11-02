<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PushPeopleToIntrasAction;
use Kanvas\Connectors\Intras\Actions\SetupPeopleClassificationInIntrasAction;
use Kanvas\Connectors\Intras\Activities\PushPeopleClassificationActivity;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Connectors\Intras\Support\IntrasPeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationFieldEnum as FieldEnum;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationSourceEnum as SourceEnum;
use Kanvas\Guild\Customers\Classification\Jobs\ClassifyPeopleJob;
use Kanvas\Guild\Customers\Models\People;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;

/**
 * Against a scratch SIPGO schema on the test MySQL server. Serial because the Intras connection
 * settings are app settings in shared Redis.
 */
#[Group('serial')]
final class PeopleClassificationIntrasSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    private const string SIPGO_DATABASE = 'intras_people_classification_test';
    private const int PARTICIPANT_ID = 701;

    private array $previousSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_setup_creates_the_fields_once_and_a_dry_run_writes_nothing(): void
    {
        $app = app(Apps::class);

        $this->assertCount(2, new SetupPeopleClassificationInIntrasAction($app, dryRun: true)->execute());
        $this->assertSame(0, $this->classificationFieldCount());

        $this->assertCount(2, new SetupPeopleClassificationInIntrasAction($app)->execute());
        $this->assertSame(2, $this->classificationFieldCount());

        $this->assertSame([], new SetupPeopleClassificationInIntrasAction($app)->execute());
        $this->assertSame(2, $this->classificationFieldCount());
    }

    public function test_push_writes_the_two_custom_fields_and_leaves_the_sipgo_nivel_alone(): void
    {
        new SetupPeopleClassificationInIntrasAction(app(Apps::class))->execute();
        $people = $this->classifiedPeople();

        new PushPeopleToIntrasAction($people, ParticipantMapper::classificationChanges($people))->execute();

        $sipgo = $this->sipgo();
        $values = $sipgo->table('participants_custom_fields as pcf')
            ->join(
                'custom_fields as cf',
                'cf.id',
                '=',
                'pcf.custom_fields_id',
            )
            ->where('pcf.participants_id', self::PARTICIPANT_ID)
            ->pluck('pcf.value', 'cf.name')
            ->all();

        $this->assertSame('GERENTE', $values[FieldEnum::LEVEL->value]);
        $this->assertSame('FINANZAS', $values[FieldEnum::AREA->value]);
        $this->assertSame(55, (int) $sipgo->table('participants')->where('id', self::PARTICIPANT_ID)->value('participants_levels_id'));
    }

    public function test_activity_skips_a_person_waiting_on_review_or_not_linked_to_sipgo(): void
    {
        $people = $this->classifiedPeople();
        $people->set(FieldEnum::SOURCE->value, SourceEnum::PENDING_REVIEW->value);

        $this->assertFalse($this->activity()->execute($people, app(Apps::class), [])['pushed']);

        $people->set(FieldEnum::SOURCE->value, SourceEnum::AI->value);
        $people->del(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        $this->assertFalse($this->activity()->execute($people, app(Apps::class), [])['pushed']);
    }

    public function test_the_classification_never_enters_the_approval_gated_people_diff(): void
    {
        $people = $this->classifiedPeople();

        $changes = new DiffPeopleWithIntrasAction($people)->execute();

        $this->assertArrayNotHasKey(FieldEnum::LEVEL->value, $changes['custom_fields'] ?? []);
        $this->assertArrayNotHasKey(FieldEnum::AREA->value, $changes['custom_fields'] ?? []);
    }

    public function test_the_pull_queues_a_new_participant_and_skips_a_deleted_one(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $company->set(PeopleClassificationConfigurationEnum::ENABLED->value, true);
        $company->set(PeopleClassificationConfigurationEnum::TAXONOMY->value, IntrasPeopleTaxonomy::VALUE);
        Queue::fake();

        try {
            $this->sipgo()->table('participants')->insert([
                'id' => self::PARTICIPANT_ID + 1,
                'first_name' => 'Borrada',
                'last_name' => 'Pérez',
                'position' => 'Gerente de Finanzas',
                'is_deleted' => 1,
            ]);

            new PullParticipantsFromIntrasAction(app(Apps::class), $company, static::$cachedUser)->execute();
        } finally {
            $company->del(PeopleClassificationConfigurationEnum::ENABLED->value);
            $company->del(PeopleClassificationConfigurationEnum::TAXONOMY->value);
        }

        Queue::assertPushed(ClassifyPeopleJob::class, 1);
        Queue::assertPushed(ClassifyPeopleJob::class, fn (ClassifyPeopleJob $job) => $job->people->firstname === 'Ana');
    }

    public function test_the_pull_queues_nothing_when_the_feature_is_off(): void
    {
        Queue::fake();

        new PullParticipantsFromIntrasAction(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)->execute();

        Queue::assertNotPushed(ClassifyPeopleJob::class);
    }

    private function classifiedPeople(): People
    {
        $user = static::$cachedUser;

        $people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Ana', 'lastname' => 'Pérez']);

        $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, self::PARTICIPANT_ID);
        $people->set(FieldEnum::LEVEL->value, 'GERENTE');
        $people->set(FieldEnum::AREA->value, 'FINANZAS');
        $people->set(FieldEnum::SOURCE->value, SourceEnum::AI->value);

        return $people;
    }

    private function activity(): PushPeopleClassificationActivity
    {
        return new ReflectionClass(PushPeopleClassificationActivity::class)->newInstanceWithoutConstructor();
    }

    private function classificationFieldCount(): int
    {
        return $this->sipgo()->table('custom_fields')
            ->where('modules_id', ParticipantMapper::PARTICIPANT_CUSTOM_FIELDS_MODULE_ID)
            ->whereIn('name', array_keys(ParticipantMapper::CLASSIFICATION_FIELDS))
            ->count();
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
            participants_levels_id INT UNSIGNED NULL,
            companies_id INT UNSIGNED NULL,
            companies_offices_id INT UNSIGNED NULL,
            is_key_participant TINYINT NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )");
        $fixture->statement("DROP TABLE IF EXISTS `{$db}`.participants_groups, `{$db}`.`groups`, `{$db}`.participants_interests, `{$db}`.participants_events_interests, `{$db}`.companies_offices");
        $fixture->statement("CREATE TABLE `{$db}`.`groups` (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NULL)");
        $fixture->statement("CREATE TABLE `{$db}`.participants_groups (participants_id INT UNSIGNED NOT NULL, groups_id INT UNSIGNED NOT NULL, is_deleted TINYINT NOT NULL DEFAULT 0)");
        $fixture->statement("CREATE TABLE `{$db}`.participants_interests (participants_id INT UNSIGNED NOT NULL, name VARCHAR(64) NULL, is_deleted TINYINT NOT NULL DEFAULT 0)");
        $fixture->statement("CREATE TABLE `{$db}`.participants_events_interests (participants_id INT UNSIGNED NOT NULL, events_id INT UNSIGNED NULL, participants_referral_id INT UNSIGNED NULL, is_deleted TINYINT NOT NULL DEFAULT 0)");
        $fixture->statement("CREATE TABLE `{$db}`.companies_offices (id INT UNSIGNED NOT NULL PRIMARY KEY)");
        $fixture->statement("CREATE TABLE `{$db}`.custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            modules_id INT UNSIGNED NOT NULL,
            type VARCHAR(32) NULL,
            label VARCHAR(64) NULL,
            name VARCHAR(64) NOT NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.participants_custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            participants_id INT UNSIGNED NOT NULL,
            custom_fields_id INT UNSIGNED NOT NULL,
            value VARCHAR(64) NULL
        )");

        $fixture->table("{$db}.participants")->insert([
            'id' => self::PARTICIPANT_ID,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'full_name' => 'Ana Pérez',
            'position' => 'Gerente de Finanzas',
            'participants_levels_id' => 55,
        ]);
    }
}
