<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EngagementReferenceMigrationTest extends TestCase
{
    public function testMigratesUnsignedLeadIdsInPlaceAndCanBeRerunAndRolledBack(): void
    {
        $manager = app('db');
        $source = $manager->connection('action_engine');
        $database = 'engagement_migration_' . bin2hex(random_bytes(8));
        $source->statement('CREATE DATABASE `' . $database . '`');

        try {
            config(['database.connections.engagement_migration_test' => [
                ...config('database.connections.action_engine'),
                'url' => null,
                'database' => $database,
            ]]);
            $connection = $manager->connection('engagement_migration_test');
            $schema = $connection->getSchemaBuilder();
            $schema->create('engagements', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->unsignedBigInteger('companies_id');
                $table->unsignedInteger('leads_id')->index();
                $table->bigInteger('people_id')->nullable();
            });
            $connection->table('engagements')->insert(['companies_id' => 1, 'leads_id' => 3000000000]);

            Schema::shouldReceive('connection')->with('action_engine')->andReturn($schema);
            DB::shouldReceive('connection')->with('action_engine')->andReturn($connection);

            $migration = require base_path('database/migrations/ActionEngine/2026_11_07_000000_add_reference_to_engagements.php');
            $migration->up();
            $migration->up();

            $column = $connection->selectOne("SHOW COLUMNS FROM engagements LIKE 'leads_id'");
            $this->assertSame('int unsigned', $column->Type);
            $this->assertSame('YES', $column->Null);
            $this->assertSame(3000000000, (int) $connection->table('engagements')->value('leads_id'));
            $this->assertTrue($schema->hasIndex('engagements', 'engagements_entity_type_entity_id_index'));
            $this->assertTrue($schema->hasIndex('engagements', 'engagements_companies_id_entity_type_entity_id_index'));

            $connection->table('engagements')->insert([
                'companies_id' => 1,
                'leads_id' => null,
                'entity_type' => 'invoice',
                'entity_id' => 123,
            ]);
            $migration->down();
            $migration->down();

            $this->assertFalse($schema->hasColumn('engagements', 'entity_type'));
            $this->assertFalse($schema->hasColumn('engagements', 'entity_id'));
            $this->assertSame(2, $connection->table('engagements')->count());
        } finally {
            $manager->purge('engagement_migration_test');
            $source->statement('DROP DATABASE `' . $database . '`');
        }
    }
}
