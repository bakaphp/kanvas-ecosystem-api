<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Kanvas\Notifications\Models\Types;
use Kanvas\SystemModules\Models\SystemModules;

class NotificationTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        $systemModule = SystemModules::where('slug', 'users')->first();
        Types::create([
            'apps_id' => 1,
            'system_modules_id' => $systemModule->id,
            'parent_id' => 0,
            'name' => 'users',
            'key' => 'users',
            'description' => 'users',
            'template' => 'users',
            'icon_url' => 'users',
            'with_realtime' => 0,
            'is_published' => 1,
            'is_deleted' => 0,
            'weight' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
