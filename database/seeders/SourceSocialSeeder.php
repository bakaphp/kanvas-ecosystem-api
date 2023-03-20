<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SourceSocialSeeder extends Seeder
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
                'title' => 'twitter',
                'url' => 'twitter.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ],
            [
                'title' => 'twitter-oauth-2',
                'url' => 'twitter.com',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0
            ]
        );
    }
}
