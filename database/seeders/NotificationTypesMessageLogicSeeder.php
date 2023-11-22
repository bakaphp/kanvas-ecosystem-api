<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Social\MessagesTypes\Models\MessagesTypes;

class NotificationTypesMessageLogicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $notificationType = NotificationTypes::latest()->first();
        $messageType = MessagesTypes::latest()->first();
        DB::table('notification_types_message_logic')->insert(
            [
                'apps_id' => 1,
                'messages_type_id' => $messageType->getId(),
                'notifications_type_id' => $notificationType->getId(),
                'logic' => '{
                    "conditions": "message.is_public == 1 and message.is_published == 1"
                }',
                'created_at' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
            ]
        );
    }
}
