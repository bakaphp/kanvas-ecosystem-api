<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;

/**
 * Pomerado's hosted server: ready-made tools that do one task on one website (search flights, check an
 * order, fill a form, check out), found by task or site, run as jobs, and built on request when none exists.
 *
 * Probed 2026-11-25. `initialize` → 401 naming the path-aware protected-resource document (the origin-level
 * one 404s) → `auth.pomerado.ai`, RFC 8414 at its root, PKCE S256, public clients, `refresh_token` grant.
 * `/oauth2/register` took the payload Kanvas sends with an arbitrary https callback first try, so it is one
 * click. `/oauth2/authorize` validates all three inputs (`invalid_scope`, `invalid_target`,
 * `invalid_redirect_uri`) and accepted the MCP URL as `resource`. The resource advertises no scopes; the
 * authorization server lists `openid`, `profile`, `email`, `offline_access`, and the row asks for `openid` plus
 * `offline_access` so a refresh token can come back. A pasted `pom_` key is a plain bearer.
 *
 * Every run and build is a job. A run tool holds the call up to 50 s and usually answers finished; a build
 * takes minutes. The job's id comes back as `id` while `get_job` takes `job_id`, hence `status_id_argument`.
 * `needs_input` and `paused` are done statuses on purpose: both wait on a person, so the agent is woken to
 * hand over the job's answer page. `watch_url` is not declared as the live URL: it is the connecting
 * account's dashboard, and the poll would post it into whatever conversation the agent is in.
 *
 * 250 s per call: `get_job` holds for the `wait_seconds` the model passes and the server's own `next` hint
 * asks for up to 240.
 *
 * Left out: `sign_in` returns an API key into the conversation, `report_issue` writes to the vendor on the
 * person's behalf, and the four `*_pomerado_api` tools open the rest of the account (API keys, team members,
 * billing, saved logins, webhooks). The cost is that resuming a paused build and changing where a job's
 * questions go are done on Pomerado's dashboard. Widening is a decision, not a fix.
 *
 * The signed-in tool list is unprobed (it needs a credential): names and shapes come from the keyless
 * endpoint and the vendor's MCP reference, and `build_website_tool`'s answer is assumed to be the same job
 * object a run returns. A start result without `id` is not handed off, so a wrong guess costs nothing.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string NAME = 'pomerado_mcp';

    private const array DONE_STATUSES = [
        'needs_input',
        'paused',
        'succeeded',
        'failed',
        'cancelled',
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
                'vendor' => 'Pomerado',
                'transport' => 'http',
                'url' => 'https://mcp.pomerado.ai/mcp',
                'auth_methods' => [
                    'oauth',
                    'bearer',
                ],
                'oauth' => [
                    'scopes' => [
                        'openid',
                        'offline_access',
                    ],
                ],
                'prefix' => 'pomerado',
                'exclude' => [
                    'sign_in',
                    'report_issue',
                    'search_pomerado_api',
                    'describe_pomerado_api',
                    'call_read_only_pomerado_api',
                    'call_pomerado_api',
                ],
                'timeout_ms' => 250000,
                'url_per_connection' => false,
                'async_jobs' => [
                    'run_read_only_website_tool' => $this->job(pollSeconds: 20, timeoutSeconds: 6000),
                    'run_website_tool' => $this->job(pollSeconds: 20, timeoutSeconds: 6000),
                    'build_website_tool' => $this->job(pollSeconds: 60, timeoutSeconds: 7200),
                    'change_website_tool' => $this->job(pollSeconds: 60, timeoutSeconds: 7200),
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
            ->where('name', self::NAME)
            ->where('type', IntegrationTypeEnum::MCP->value)
            ->where('apps_id', 0)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $pollSeconds, int $timeoutSeconds): array
    {
        return [
            'status_tool' => 'get_job',
            'id_field' => 'id',
            'status_id_argument' => 'job_id',
            'status_field' => 'status',
            'done_statuses' => self::DONE_STATUSES,
            'poll_seconds' => $pollSeconds,
            'timeout_seconds' => $timeoutSeconds,
        ];
    }
};
