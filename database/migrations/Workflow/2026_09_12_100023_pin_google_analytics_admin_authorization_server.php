<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Analytics ADMIN server publishes no OAuth metadata: `/.well-known/oauth-protected-resource`, its
 * path-aware form, `oauth-authorization-server` and `openid-configuration` all 404 on that host. With
 * nothing to discover, the MCP spec defaults apply and the consent screen becomes
 * `https://analyticsadmin.googleapis.com/authorize`, which does not exist — Google's is
 * `accounts.google.com`.
 *
 * So the issuer is pinned. Its sibling, the Analytics DATA server, needs no pin: that host does publish
 * protected-resource metadata naming accounts.google.com, and discovery follows it correctly.
 *
 * Cached discovery is keyed per server and lives a day, so an entry written before this migration must be
 * forgotten for the change to take effect.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        $this->write('https://accounts.google.com');
    }

    public function down(): void
    {
        $this->write(null);
    }

    private function write(?string $issuer): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', 'google_analytics_admin_mcp')
            ->where('apps_id', 0)
            ->first();

        if ($row === null) {
            return;
        }

        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $oauth = is_array($metadata['oauth'] ?? null) ? $metadata['oauth'] : [];

        if ($issuer === null) {
            unset($oauth['authorization_server']);
        } else {
            $oauth['authorization_server'] = $issuer;
        }

        $metadata['oauth'] = $oauth;

        DB::connection('workflow')->table('integrations')
            ->where('id', $row->id)
            ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
    }
};
