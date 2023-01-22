<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Templates\Models\Templates;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'user-signup',
            'template' => '{{$name}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Templates::create([
            'apps_id' => 1,
            'users_id' => 1,
            'companies_id' => 1,
            'name' => 'users-invite',
            'template' => '{{$name}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
