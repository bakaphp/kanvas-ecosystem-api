<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('sources')->insert(
            [
                'title' => 'baka',
                'url' => 'baka.io',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'androipapp',
                'url' => 'bakaapp.io',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'iosapp',
                'url' => 'bakaios.io',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'google',
                'url' => 'google.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'facebook',
                'url' => 'facebook.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'github',
                'url' => 'github.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'apple',
                'url' => 'apple.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'webapp',
                'url' => 'webapp.io',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ]
        );
    }
}
