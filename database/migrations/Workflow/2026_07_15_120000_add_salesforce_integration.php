<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers Salesforce against the generic integration mechanism. Setup runs through the shared
 * `integrationCompany` mutation, which reads `handler` from this row and calls
 * SalesforceHandler::setup() — so the connector ships no GraphQL of its own.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'salesforce')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'client_id' => ['type' => 'text', 'required' => true],
            'client_secret' => ['type' => 'text', 'required' => true],
            // 'refresh_token' grant (default, Authorization Code flow done once) or
            // 'client_credentials' (server-to-server, no user context, no refresh_token at all —
            // see SalesforceHandler::setup()/Client.php for how each is validated/used).
            'grant_type' => ['type' => 'text', 'required' => false],
            'refresh_token' => ['type' => 'text', 'required' => false],
            'login_url' => ['type' => 'text', 'required' => false],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'salesforce',
            'handler' => 'Kanvas\\Connectors\\Salesforce\\Handlers\\SalesforceHandler',
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
            ->where('name', 'salesforce')
            ->where('apps_id', 0)
            ->delete();
    }
};
