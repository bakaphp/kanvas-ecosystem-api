<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationChannelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('notification_channels')->insert(
            [
                'name' => 'Email',
                'slug' => 'email',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ],
            [
                'name' => 'Push',
                'slug' => 'push',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ]
        );
    }
}
