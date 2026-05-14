<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Seeds global (apps_id=0, companies_id=0) defaults for AgentRuntime lifecycle email
// templates by reading the Blade files from resources/views/emails/agent_runtime/. Runs
// AFTER TemplateSeeder in DatabaseSeeder — TemplateSeeder inserts rows with explicit ids
// (1, 2, ...), so any insertion before it grabs those id values and breaks the seeder
// with a duplicate-primary-key error.
class AgentRuntimeEmailTemplateSeeder extends Seeder
{
    private const string TEMPLATE_DIR = 'views/emails/agent_runtime';

    private const array TEMPLATES = [
        'agent_deployment_launched',
        'agent_deployment_terminated',
        'agent_deployment_failed',
        'agent_backup_result',
        'agent_migration_result',
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $name) {
            $path = resource_path(self::TEMPLATE_DIR . '/' . $name . '.blade.php');
            if (! File::exists($path)) {
                continue;
            }

            $exists = DB::table('email_templates')
                ->where('name', $name)
                ->where('apps_id', 0)
                ->where('companies_id', 0)
                ->where('is_deleted', 0)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('email_templates')->insert([
                'apps_id' => 0,
                'users_id' => 1,
                'companies_id' => 0,
                'parent_template_id' => null,
                'name' => $name,
                'template' => File::get($path),
                'created_at' => now(),
                'is_deleted' => 0,
            ]);
        }
    }
}
