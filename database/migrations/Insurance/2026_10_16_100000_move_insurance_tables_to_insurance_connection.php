<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'insurance';

    private const array TABLES = [
        'insurers',
        'insurance_plans',
        'insurance_coverages',
        'insurance_plan_exclusions',
        'insurance_plan_requirements',
        'insurance_vehicle_model_map',
    ];

    public function up(): void
    {
        $source = DB::connection('commerce');
        $target = DB::connection('insurance');

        foreach (self::TABLES as $table) {
            if (! Schema::connection('insurance')->hasTable($table) && Schema::connection('commerce')->hasTable($table)) {
                $target->unprepared($source->selectOne('SHOW CREATE TABLE `' . $table . '`')->{'Create Table'});

                $source->table($table)->orderBy('id')->chunkById(
                    500,
                    fn ($rows) => $target->table($table)->insert($rows->map(fn ($row): array => (array) $row)->all())
                );
            }
        }

        $source->statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::connection('insurance')->hasTable($table)) {
                Schema::connection('commerce')->dropIfExists($table);
            }
        }

        $source->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
    }
};
