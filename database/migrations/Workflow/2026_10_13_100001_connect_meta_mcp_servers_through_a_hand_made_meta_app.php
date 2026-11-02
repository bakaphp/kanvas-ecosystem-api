<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads and WhatsApp Business were unreachable because Meta refuses dynamic client registration — and
 * on 2026-09-12 nothing else was documented. Meta's setup guide now says a developer's own app works
 * (`--client-id <META_APP_ID>` after adding the server's use case to the app, redirect URL configured on
 * it) and that a user access token carrying the server's permissions is accepted as a bearer. Re-probed
 * 2026-10-13: registration is still refused for every payload, the rest is unchanged.
 *
 * So both rows gain `client_key: meta` — one hand-made Meta app shared with the Developer Tools row, set
 * on the app as `mcp_oauth_client_id_meta` — and offer the bearer method beside OAuth. Meta Ads also
 * asks for `pages_manage_ads`, the eighth scope its resource now advertises (it was seven at launch).
 * WhatsApp's OAuth scopes are the three the resource names; its exclusions are untouched.
 *
 * Discovery is not forgotten here: the endpoints did not change, only the client and scopes.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const array SERVERS = [
        'meta_ads_mcp' => [
            'auth_methods' => ['oauth', 'bearer'],
            'scopes' => [
                'ads_management',
                'ads_read',
                'catalog_management',
                'business_management',
                'pages_show_list',
                'pages_manage_ads',
                'instagram_basic',
                'ads_mcp_management',
            ],
        ],
        'whatsapp_business_mcp' => [
            'auth_methods' => ['bearer', 'oauth'],
            'scopes' => [
                'business_management',
                'whatsapp_business_management',
                'whatsapp_business_messaging',
            ],
        ],
    ];

    private const array BEFORE = [
        'meta_ads_mcp' => [
            'auth_methods' => ['oauth'],
            'scopes' => [
                'ads_management',
                'ads_read',
                'catalog_management',
                'business_management',
                'pages_show_list',
                'instagram_basic',
                'ads_mcp_management',
            ],
        ],
        'whatsapp_business_mcp' => [
            'auth_methods' => ['bearer'],
            'scopes' => null,
        ],
    ];

    public function up(): void
    {
        foreach ($this->rows() as $row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            $server = self::SERVERS[$row->name];

            $metadata['auth_methods'] = $server['auth_methods'];
            $metadata['oauth'] = [
                ...(is_array($metadata['oauth'] ?? null) ? $metadata['oauth'] : []),
                'client_key' => 'meta',
                'scopes' => $server['scopes'],
            ];

            $this->save($row->id, $metadata);
        }
    }

    public function down(): void
    {
        foreach ($this->rows() as $row) {
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            $before = self::BEFORE[$row->name];

            $metadata['auth_methods'] = $before['auth_methods'];
            unset($metadata['oauth']);

            if ($before['scopes'] !== null) {
                $metadata['oauth'] = ['scopes' => $before['scopes']];
            }

            $this->save($row->id, $metadata);
        }
    }

    private function rows(): iterable
    {
        return DB::connection('workflow')->table('integrations')
            ->whereIn('name', array_keys(self::SERVERS))
            ->where('type', 'mcp')
            ->where('apps_id', 0)
            ->get(['id', 'name', 'metadata']);
    }

    private function save(int $id, array $metadata): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('id', $id)
            ->update([
                'metadata' => json_encode($metadata),
                'updated_at' => now(),
            ]);
    }
};
