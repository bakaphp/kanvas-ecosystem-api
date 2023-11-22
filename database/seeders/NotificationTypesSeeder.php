<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kanvas\Notifications\Models\NotificationTypes;
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
        $systemModule = SystemModules::first();
        NotificationTypes::create([
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
        NotificationTypes::create([
            'apps_id' => 1,
            'system_modules_id' => $systemModule->id,
            'parent_id' => 0,
            'name' => 'blank',
            'key' => 'blank',
            'description' => 'blank',
            'template' => 'blank',
            'icon_url' => 'blank',
            'with_realtime' => 0,
            'is_published' => 1,
            'is_deleted' => 0,
            'weight' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        NotificationTypes::create([
            'apps_id' => 1,
            'system_modules_id' => $systemModule->id,
            'parent_id' => 0,
            'notification_channel_id' => 1,
            'name' => 'email-notification-default',
            'verb' => 'entity',
            'event' => 'creation',
            'template_id' => 1,
            'key' => 'email-notification-default',
            'description' => 'blank',
            'template' => 'blank',
            'icon_url' => 'blank',
            'with_realtime' => 0,
            'is_published' => 1,
            'is_deleted' => 0,
            'weight' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        NotificationTypes::create([
            'apps_id' => 1,
            'system_modules_id' => $systemModule->id,
            'parent_id' => 0,
            'notification_channel_id' => 2,
            'name' => 'push-notification-default',
            'verb' => 'entity',
            'event' => 'creation',
            'template_id' => 6,
            'key' => 'push-notification-default',
            'description' => 'blank',
            'template' => 'blank',
            'icon_url' => 'blank',
            'with_realtime' => 0,
            'is_published' => 1,
            'is_deleted' => 0,
            'weight' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
