<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\KanvasModules\Models\KanvasModule;

class KanvasModulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::ECOSYSTEM->value,
                'name' => 'Ecosystem',
            ]
        );
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::INVENTORY->value,
                'name' => 'Inventory',
            ]
        );
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::CRM->value,
                'name' => 'CRM',
            ]
        );
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::SOCIAL->value,
                'name' => 'Social',
            ]
        );
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::WORKFLOW->value,
                'name' => 'WORKFLOW',
            ]
        );
        KanvasModule::firstOrCreate(
            [
                'id'   => KanvasModuleEnum::ACTION_ENGINE->value,
                'name' => 'Action Engine',
            ]
        );
    }
}
