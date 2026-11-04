<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Models\StoredWorkflow;

/**
 * A scratch SIPGO schema on the test MySQL server, shaped like the real tables (the *_custom_fields
 * tables have no unique key; custom_fields names repeat across modules). Tests using it are serial:
 * the Intras connection settings are app settings in shared Redis.
 */
trait UsesSipgoScratchSchema
{
    protected const string SIPGO_DATABASE = 'intras_people_sync_test';
    protected const int PARTICIPANT_ID = 501;
    protected const int FACILITATOR_ID = 601;
    protected const int COMPANY_ID = 701;

    private array $previousSettings = [];

    protected function sipgo(): ConnectionInterface
    {
        return new Client(app(Apps::class))->getConnection();
    }

    protected function pointIntrasAtScratchDatabase(): void
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
     * An activity that can go through executeIntegration (needs a stored workflow for the history row).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function runnableActivity(string $class): object
    {
        return new $class(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );
    }

    protected function seedPeoplePolicy(): void
    {
        $this->artisan('kanvas:approvals:seed-people-policy', [
            'apps_id' => app(Apps::class)->getId(),
            'company_id' => static::$cachedUser->getCurrentCompany()->getId(),
            '--connector' => 'intras',
        ])->assertSuccessful();
    }

    protected function addContact(
        People $people,
        ContactTypeEnum $type,
        string $value,
        int $weight = 0
    ): void {
        Contact::create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $this->contactTypeId($type),
            'value' => $value,
            'weight' => $weight,
        ]);
    }

    protected function contactTypeId(ContactTypeEnum $type): int
    {
        return ContactType::getByName($type->getName())->getId();
    }

    protected function restoreIntrasSettings(): void
    {
        $app = app(Apps::class);

        foreach ($this->previousSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }
    }

    /**
     * Through its own connection: DDL on `mysql` would implicitly commit the test's transaction.
     */
    protected function createSipgoSchema(): void
    {
        Config::set('database.connections.intras_fixture', [
            ...config('database.connections.mysql'),
            'database' => '',
        ]);

        $fixture = DB::connection('intras_fixture');
        $db = self::SIPGO_DATABASE;

        $fixture->statement("CREATE DATABASE IF NOT EXISTS `{$db}`");
        $fixture->statement("DROP TABLE IF EXISTS `{$db}`.participants, `{$db}`.custom_fields, `{$db}`.participants_custom_fields, `{$db}`.participants_levels, `{$db}`.participants_statuses, `{$db}`.cities, `{$db}`.facilitators, `{$db}`.facilitators_custom_fields, `{$db}`.facilitators_statuses, `{$db}`.countries, `{$db}`.companies, `{$db}`.companies_custom_fields, `{$db}`.business_sectors, `{$db}`.companies_offices");
        $fixture->statement("CREATE TABLE `{$db}`.participants (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            companies_id INT UNSIGNED NULL,
            first_name VARCHAR(64) NOT NULL,
            last_name VARCHAR(64) NOT NULL,
            full_name VARCHAR(130) NULL,
            position VARCHAR(64) NULL,
            identification VARCHAR(16) NULL,
            participants_levels_id INT UNSIGNED NULL,
            participants_statuses_id INT UNSIGNED NOT NULL,
            departments_id VARCHAR(254) NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.participants_levels (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->statement("CREATE TABLE `{$db}`.cities (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->statement("CREATE TABLE `{$db}`.participants_statuses (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->table("{$db}.participants_statuses")->insert([['id' => 1, 'name' => 'Activo'], ['id' => 2, 'name' => 'Inactivo']]);
        $fixture->table("{$db}.participants_levels")->insert([
            ['id' => 4, 'name' => 'Coordinador'],
            ['id' => 7, 'name' => 'Gerente'],
        ]);
        $fixture->table("{$db}.cities")->insert([
            ['id' => 10, 'name' => 'Santiago'],
            ['id' => 11, 'name' => 'Santiago '],
        ]);
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
            'participants_levels_id' => 4,
            'participants_statuses_id' => 1,
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $fixture->table("{$db}.custom_fields")->insert([
            ['id' => 42, 'modules_id' => 3, 'name' => 'email_oficina'],
            ['id' => 56, 'modules_id' => 4, 'name' => 'sexo'],
            ['id' => 65, 'modules_id' => 4, 'name' => 'celular_1'],
            ['id' => 66, 'modules_id' => 4, 'name' => 'celular_2'],
            ['id' => 67, 'modules_id' => 4, 'name' => 'email_oficina'],
            ['id' => 68, 'modules_id' => 4, 'name' => 'email_personal'],
            ['id' => 80, 'modules_id' => 3, 'name' => 'email'],
            ['id' => 81, 'modules_id' => 3, 'name' => 'celular'],
            ['id' => 90, 'modules_id' => 1, 'name' => 'tamano'],
            ['id' => 91, 'modules_id' => 1, 'name' => 'telefono'],
        ]);

        $fixture->statement("CREATE TABLE `{$db}`.facilitators (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            first_name VARCHAR(64) NOT NULL,
            last_name VARCHAR(64) NOT NULL,
            identification VARCHAR(16) NULL,
            cv TEXT NULL,
            facilitators_statuses_id INT UNSIGNED NULL,
            home_countries_id INT UNSIGNED NULL,
            events_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.facilitators_custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            facilitators_id INT UNSIGNED NOT NULL,
            custom_fields_id INT UNSIGNED NOT NULL,
            value VARCHAR(64) NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.facilitators_statuses (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->statement("CREATE TABLE `{$db}`.countries (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->table("{$db}.countries")->insert([['id' => 1, 'name' => 'República Dominicana']]);
        $fixture->table("{$db}.facilitators_statuses")->insert([
            ['id' => 1, 'name' => 'Activo'],
            ['id' => 2, 'name' => 'Inactivo'],
        ]);
        $fixture->table("{$db}.facilitators")->insert([
            'id' => self::FACILITATOR_ID,
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'identification' => '00112345678',
            'cv' => 'Consultora senior',
            'facilitators_statuses_id' => 1,
        ]);
        $fixture->table("{$db}.facilitators_custom_fields")->insert([
            ['facilitators_id' => self::FACILITATOR_ID, 'custom_fields_id' => 80, 'value' => 'Ana@Example.com'],
        ]);

        $fixture->statement("CREATE TABLE `{$db}`.companies (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            name VARCHAR(128) NOT NULL,
            rnc VARCHAR(32) NULL,
            classification VARCHAR(64) NULL,
            classification_open VARCHAR(64) NULL,
            potentiality VARCHAR(64) NULL,
            is_prospect TINYINT(1) NOT NULL DEFAULT 0,
            is_supplier TINYINT(1) NOT NULL DEFAULT 0,
            total_employees INT NULL,
            quotes_investment_range VARCHAR(64) NULL,
            linked_companies_id INT UNSIGNED NULL,
            business_sectors_id INT UNSIGNED NULL,
            quotes_count INT NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.companies_offices (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            companies_id INT UNSIGNED NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0
        )");
        $fixture->statement("CREATE TABLE `{$db}`.companies_custom_fields (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            companies_id INT UNSIGNED NOT NULL,
            custom_fields_id INT UNSIGNED NOT NULL,
            value VARCHAR(64) NULL
        )");
        $fixture->statement("CREATE TABLE `{$db}`.business_sectors (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $fixture->table("{$db}.business_sectors")->insert([
            ['id' => 3, 'name' => 'Industria'],
            ['id' => 5, 'name' => 'Banca'],
        ]);
        $fixture->table("{$db}.companies")->insert([
            'id' => self::COMPANY_ID,
            'name' => 'ACME SRL',
            'rnc' => '101000001',
            'is_prospect' => 1,
            'total_employees' => 40,
            'business_sectors_id' => 3,
            'quotes_count' => 2,
        ]);
        $fixture->table("{$db}.companies")->insert(['id' => 702, 'name' => 'ACME Holding', 'rnc' => '101000002']);
        $fixture->table("{$db}.companies_custom_fields")->insert([
            ['companies_id' => self::COMPANY_ID, 'custom_fields_id' => 90, 'value' => 'Mediana'],
        ]);

        $fixture->table("{$db}.participants_custom_fields")->insert([
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 56, 'value' => 'F'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 65, 'value' => '809-555-1234'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 67, 'value' => 'Ana@Example.com'],
            ['participants_id' => self::PARTICIPANT_ID, 'custom_fields_id' => 67, 'value' => 'Ana@Example.com'],
        ]);
    }
}
