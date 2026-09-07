<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers the generic "send a Slack notification" integration against the shared integration
 * mechanism. The `integrationCompany` mutation reads `handler` from this row and calls
 * SlackNotificationHandler::setup(), which validates and stores a webhook URL and/or bot token on
 * the company. This is separate from the Slack agent/listener install flow (manifest-driven,
 * receiver-backed) — it only supports one-way notifications for workflow rules.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'slack')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'webhook_url' => ['type' => 'text', 'required' => false],
            'bot_token' => ['type' => 'text', 'required' => false],
            'default_channel' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'slack',
            'handler' => 'Kanvas\\Connectors\\Slack\\Handlers\\SlackNotificationHandler',
            'apps_id' => 0,
            'config' => json_encode($config),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'slack')
            ->where('apps_id', 0)
            ->delete();
    }
};
