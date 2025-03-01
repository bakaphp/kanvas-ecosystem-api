<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Enums\SourceEnum;

class LanguagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('languages')->insert(
        [
            [
                'title' => 'English',
                'name' => 'English',
                'code' => 'en',
                'order' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ],
            [
                'title' => 'Español',
                'name' => 'Español',
                'code' => 'es',
                'order' => 2,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ],
            [
                'title' => 'French',
                'name' => 'French',
                'code' => 'fr',
                'order' => 3,
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ],
        ]
        );
    }
}
