<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('message_types')->insert(
            [
                'uuid' => (string) Str::uuid(),
                'apps_id' => 1,
                'languages_id' => 'Email',
                'name' => 'entity',
                'verb' => 'entity',
                'template' => null,
                'templates_plura' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ]
        );
    }
}
