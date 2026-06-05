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
    /**
     * The classification (is_internal / is_default) lives here, not in a
     * migration: on a fresh DB the migration runs before this seeder, so
     * any UPDATE-by-id from a migration silently no-ops. Seed the flags
     * at row-create time so every environment lands consistent.
     *
     * @var array<int, array{name: string, is_internal: bool, is_default: bool}>
     */
    private array $modules = [
        KanvasModuleEnum::ECOSYSTEM->value     => ['name' => 'Ecosystem',     'is_internal' => true,  'is_default' => true],
        KanvasModuleEnum::INVENTORY->value     => ['name' => 'Inventory',     'is_internal' => false, 'is_default' => true],
        KanvasModuleEnum::CRM->value           => ['name' => 'CRM',           'is_internal' => false, 'is_default' => true],
        KanvasModuleEnum::SOCIAL->value        => ['name' => 'Social',        'is_internal' => false, 'is_default' => true],
        KanvasModuleEnum::WORKFLOW->value      => ['name' => 'WORKFLOW',      'is_internal' => true,  'is_default' => true],
        KanvasModuleEnum::ACTION_ENGINE->value => ['name' => 'Action Engine', 'is_internal' => true,  'is_default' => true],
        KanvasModuleEnum::AI->value            => ['name' => 'AI',            'is_internal' => false, 'is_default' => true],
        KanvasModuleEnum::COMMERCE->value      => ['name' => 'Commerce',      'is_internal' => false, 'is_default' => true],
    ];

    public function run(): void
    {
        // updateOrCreate (not firstOrCreate) so the seeder also normalizes
        // any rows that drifted from the canonical name/flags — e.g. id=4
        // showing up as "Knowledge base" instead of "Social". Safe to re-run
        // manually any time the catalog needs realigning.
        foreach ($this->modules as $id => $attrs) {
            KanvasModule::updateOrCreate(
                ['id' => $id],
                [
                    'name' => $attrs['name'],
                    'is_internal' => $attrs['is_internal'],
                    'is_default' => $attrs['is_default'],
                ],
            );
        }
    }
}
