<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Notifications\Models\NotificationChannel;

class NotificationChannelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        NotificationChannel::create([
            'name' => 'mail',
            'slug' => 'mail',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);

        NotificationChannel::create([
            'name' => 'push',
            'slug' => 'push',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
        NotificationChannel::create([
            'name' => 'database',
            'slug' => 'database',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
        NotificationChannel::create([
            'name' => 'realtime',
            'slug' => 'realtime',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
        NotificationChannel::create([
            'name' => 'sms',
            'slug' => 'sms',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
    }
}
