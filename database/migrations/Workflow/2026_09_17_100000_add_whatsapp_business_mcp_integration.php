<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Meta's WhatsApp Business Tools server: WABAs, phone-number onboarding, message templates and sending.
 *
 * Key only, no OAuth. The metadata looks one-click (`registration_endpoint`, PKCE S256, public clients),
 * but the endpoint answers "Dynamic registration is not available for this client" to every payload —
 * the same allow-list that hid Meta Ads. The documented clients are Claude and ChatGPT only.
 * The key is a system-user token carrying the three scopes the resource advertises.
 *
 * Excluded on purpose:
 *  - webhook configure/subscribe — a WABA already wired to Kanvas receives inbound messages through those
 *    webhooks, and one agent call would silently repoint them;
 *  - payments configuration — not an agent decision;
 *  - `system_user_token` — returns a Business Manager link, useless inside a turn.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'whatsapp_business_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'whatsapp_business_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'WhatsApp Business',
                'url' => 'https://mcp.facebook.com/whatsapp_business_tools',
                'transport' => 'http',
                'auth_methods' => ['bearer'],
                'prefix' => 'whatsapp',
                'exclude' => [
                    'whatsapp_biz_configure_webhooks',
                    'whatsapp_biz_subscribe_webhook',
                    'whatsapp_biz_configure_payments',
                    'whatsapp_biz_system_user_token',
                ],
                'timeout_ms' => 30000,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'whatsapp_business_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
