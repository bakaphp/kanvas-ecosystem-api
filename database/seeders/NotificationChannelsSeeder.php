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
            'name' => 'Email',
            'slug' => 'email',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);

        NotificationChannel::create([
            'name' => 'Push',
            'slug' => 'push',
            'created_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ]);
    }
}
