<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Kanvas\Modules\Models\Module;
use Kanvas\Modules\Enums\ModuleEnum as KanvasModuleEnum;

class ModulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::ECOSYSTEM->value,
                'name' => 'Ecosystem',
            ]
        );
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::INVENTORY->value,
                'name' => 'Inventory',
            ]
        );
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::CRM->value,
                'name' => 'CRM',
            ]
        );
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::SOCIAL->value,
                'name' => 'Social',
            ]
        );
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::WORKFLOW->value,
                'name' => 'WORKFLOW',
            ]
        );
        Module::firstOrCreate(
            [
                'id' => KanvasModuleEnum::ACTION_ENGINE->value,
                'name' => 'Action Engine',
            ]
        );
    }
}
