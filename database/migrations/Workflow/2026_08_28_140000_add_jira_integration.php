<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Jira against the generic integration mechanism. Without this row the shared
 * `integrationCompany` mutation has no `handler` to resolve, so JiraHandler::setup() is
 * unreachable and there is no way to store the instance URL / email / API token a company
 * connects with.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'jira')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'instance_url' => ['type' => 'text', 'required' => true],
            'email' => ['type' => 'text', 'required' => true],
            'api_token' => ['type' => 'text', 'required' => true],
            'default_project_key' => ['type' => 'text', 'required' => false],
            'default_issue_type' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'jira',
            'handler' => 'Kanvas\\Connectors\\Jira\\Handlers\\JiraHandler',
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
            ->where('name', 'jira')
            ->where('apps_id', 0)
            ->delete();
    }
};
