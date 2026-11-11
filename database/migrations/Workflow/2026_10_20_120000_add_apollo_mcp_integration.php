<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Apollo.io's hosted server — prospect search, people and company enrichment, the workspace's
 * contacts, accounts, opportunities, tasks and lists, sequence enrollment, and sales analytics.
 *
 * Probed 2026-10-20: `initialize` → 401 naming the origin-level protected-resource document (the
 * path-aware one answers the same) → authorization server `https://mcp.apollo.io` itself, RFC 8414 and
 * OIDC metadata both at the origin root. Public clients (`none`), PKCE S256, `refresh_token` grant, and
 * `/api/v1/oauth/applications/register_oauth_client` accepted `{app.url}/v1/oauth/callback` first try —
 * one click, no hand-made client, no pinned server. `/authorize` is a pass-through front door to
 * `app.apollo.io` that checks nothing (a bogus scope and a bogus resource both 302 fine), so a
 * token-step `invalid_target` means `include_resource: false` (HeyGen precedent). `offline_access` is
 * not advertised and not requested; whether the token response carries a refresh token is unknown until
 * the first real consent.
 *
 * The 401 also offers an Apollo API key in `X-Api-Key` — a wrong one is refused at `initialize`, so
 * connecting proves it. That is the bearer method here; the header names where a KEY goes, an OAuth
 * token still travels as `Authorization: Bearer` (the transport tells them apart by the credential).
 * A key carries the team's whole API access and ignores scopes, which is why the denylist below exists.
 *
 * Scopes are the resource's own list minus what spends money, sends mail or touches a third party:
 * mailbox and domain purchases, composing or sending emails directly, creating, editing or approving
 * sequences, waterfall enrichment (credits across providers), the website-visitor tracker's install
 * paths, Apollo's own `agent_task`, and platform plumbing (`app_scopes`, `webhook_result_read`,
 * `ema_issuer:manage`). Enrolling a contact in an existing sequence IS requested — it is the product's
 * headline use and reversible through `remove_or_stop` — and the catalog description tells the agent
 * to confirm before it does. Widening to the excluded scopes is a decision, not a fix.
 *
 * Tool list unprobed (it needs a credential). The twelve tools the vendor documents are named
 * `apollo_{scope}`, so the denylist mirrors the scopes not requested under that convention — a name
 * that never appears is a no-op. Revisit `async_jobs` on the first real `tools/list`: bulk match and
 * `report_sync` answer inline in the REST API, nothing here is expected to run in the background.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'apollo_mcp';

    private const array SCOPES = [
        'read_user_profile',
        'mixed_people_api_search',
        'mixed_companies_search',
        'organizations_search',
        'organizations_job_posting',
        'people_match',
        'people_bulk_match',
        'organizations_enrich',
        'organizations_bulk_enrich',
        'contacts_search',
        'contact_read',
        'contact_write',
        'contact_update',
        'contacts_bulk_create',
        'account_write',
        'account_update',
        'account_bulk_create',
        'opportunities_list',
        'opportunity_read',
        'opportunity_write',
        'tasks_list',
        'tasks_create',
        'lists_create',
        'lists_update',
        'lists_add_entities',
        'lists_remove_entities',
        'tags_list',
        'users_list',
        'custom_fields_list',
        'custom_field_write',
        'custom_objects_read',
        'custom_objects_write',
        'context_center_read',
        'context_center_write',
        'phone_call_search',
        'phone_call_write',
        'phone_call_update',
        'emailer_campaigns_search',
        'emailer_campaign_read',
        'emailer_campaigns_activity_feed',
        'emailer_campaigns_add_contact_ids',
        'emailer_campaigns_remove_or_stop_contact_ids',
        'emailer_schedules_list',
        'emailer_messages_search',
        'emailer_messages_email_send_status',
        'emailer_messages_get_content',
        'email_accounts_list',
        'report_sync',
        'api_usage_stats_read',
        'credit_usage_stats_read',
        'conversation_intelligence_search',
        'conversation_intelligence_show',
        'website_visitors_read',
        'website_visitor_domain_tracker_read',
    ];

    private const array EXCLUDE = [
        'apollo_emailer_messages_create',
        'apollo_emailer_messages_send_now',
        'apollo_emailer_campaigns_create',
        'apollo_emailer_campaigns_update',
        'apollo_emailer_campaigns_approve',
        'apollo_email_account_purchase_list',
        'apollo_email_account_purchase_create',
        'apollo_domain_purchase_list',
        'apollo_domain_purchase_create',
        'apollo_domain_authentication_status',
        'apollo_waterfall_enrichments',
        'apollo_website_visitor_domain_tracker_update',
        'apollo_website_visitor_domain_tracker_send_install_email',
        'apollo_website_visitor_domain_tracker_install_script',
        'apollo_agent_task',
    ];

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => self::NAME,
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Apollo.io',
                'transport' => 'http',
                'url' => 'https://mcp.apollo.io/mcp',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'auth_header' => 'X-Api-Key',
                'oauth' => [
                    'scopes' => self::SCOPES,
                ],
                'prefix' => 'apollo',
                'exclude' => self::EXCLUDE,
                'timeout_ms' => 30000,
                'url_per_connection' => false,
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', self::NAME)
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }
};
