<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Seed the global (apps_id=0, companies_id=0) default templates for AgentRuntime lifecycle
// emails by reading the Blade files from resources/views/emails/agent_runtime/. The
// `RenderTemplateAction` looks up by name (TemplatesRepository::getByName checks app/company
// specifics first then falls back to global), so an admin can override per-app simply by
// inserting an `apps_id=<X>` row with the same name. Editing the global row updates the
// default everywhere.
return new class () extends Migration {
    private const string TEMPLATE_DIR = 'views/emails/agent_runtime';

    private const array TEMPLATES = [
        'agent_deployment_launched',
        'agent_deployment_terminated',
        'agent_deployment_failed',
        'agent_backup_result',
        'agent_migration_result',
    ];

    public function up(): void
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
                'parent_template_id' => 0,
                'name' => $name,
                'template' => File::get($path),
                'created_at' => now(),
                'is_deleted' => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('name', self::TEMPLATES)
            ->where('apps_id', 0)
            ->where('companies_id', 0)
            ->delete();
    }
};
