<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers GitHub against the generic integration mechanism, so a token is configured through the UI
 * rather than set by hand on the app. The shared `integrationCompany` mutation reads `handler` from
 * this row and calls GithubHandler::setup(), which validates the token against the named repository
 * before storing it.
 *
 * `repository` is part of the setup form but is NOT persisted as config — it exists so the handler has
 * something concrete to validate against. A token can authenticate and still not see the repo we care
 * about, and on a private repo GitHub answers 404, which is indistinguishable from a typo later.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    public function up(): void
    {
        if (DB::connection('workflow')->table('integrations')->where('name', 'github')->where('apps_id', 0)->exists()) {
            return;
        }

        $config = [
            'github_token' => ['type' => 'text', 'required' => true],
            'repository' => ['type' => 'text', 'required' => true],
        ];

        DB::connection('workflow')->table('integrations')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'github',
            'handler' => 'Kanvas\\Connectors\\Github\\Handlers\\GithubHandler',
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
            ->where('name', 'github')
            ->where('apps_id', 0)
            ->delete();
    }
};
