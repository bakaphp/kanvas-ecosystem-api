<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrowserUse\Services\BrowserUseArtifactCollector;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Browser Use's hosted server. Unlike Browserbase, its tools are not page primitives: `run_session` hands a
 * whole task to Browser Use's own browsing agent, which reports back through `get_session`. Saved logins
 * live in its browser profiles (`list_browser_profiles` → `profile_id`), so a portal login never passes
 * through our model.
 *
 * Key only, sent bare in `x-browser-use-api-key`. Probed 2026-09-19: the 401 advertises OAuth metadata
 * with a registration endpoint, but `/oauth/register` and `/oauth/authorize` both 404, so OAuth is not
 * offered. `initialize` and `tools/list` answer without any key — connecting cannot prove it; a wrong one
 * first fails on a tool call.
 *
 * `run_session` and `send_task` return as soon as the session starts; a real WISE export ran ~10 minutes
 * and 86 steps. Declared as async jobs so the agent hands off instead of polling `get_session` into the
 * per-turn run cap. `live_url` is only set while the browser is up — it reads null once the session
 * idles, so it has to be caught during the poll. Terminal statuses are the v3 `BuAgentSessionStatus`
 * values that mean the task stopped running.
 *
 * A session writes its files to a sandbox that dies with it, so `artifacts_handler` attaches the company's
 * workspace to every run and pulls what the job produced into Kanvas when it ends.
 */
return new class () extends Migration {
    private const array SESSION_JOB = [
        'status_tool' => 'get_session',
        'id_field' => 'session_id',
        'status_field' => 'status',
        'done_statuses' => ['idle', 'stopped', 'timed_out', 'error'],
        'live_url_field' => 'live_url',
        'poll_seconds' => 15,
        'timeout_seconds' => 3600,
    ];

    protected $connection = 'workflow';

    public function up(): void
    {
        $exists = DB::connection('workflow')->table('integrations')
            ->where('name', 'browser_use_mcp')
            ->where('apps_id', 0)
            ->exists();

        if ($exists) {
            return;
        }

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'browser_use_mcp',
            'handler' => McpHandler::class,
            'type' => IntegrationTypeEnum::MCP->value,
            'apps_id' => 0,
            'config' => json_encode([]),
            'metadata' => json_encode([
                'vendor' => 'Browser Use',
                'transport' => 'http',
                'url' => 'https://api.browser-use.com/v3/mcp',
                'auth_methods' => ['bearer'],
                'auth_header' => 'x-browser-use-api-key',
                'prefix' => 'browser_use',
                'exclude' => [],
                'timeout_ms' => 60000,
                'url_per_connection' => false,
                'artifacts_handler' => BrowserUseArtifactCollector::class,
                'async_jobs' => [
                    'run_session' => self::SESSION_JOB,
                    'send_task' => self::SESSION_JOB,
                ],
            ]),
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('workflow')->table('integrations')
            ->where('name', 'browser_use_mcp')
            ->where('apps_id', 0)
            ->delete();
    }
};
