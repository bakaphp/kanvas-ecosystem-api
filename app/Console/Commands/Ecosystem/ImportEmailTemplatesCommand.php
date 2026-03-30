<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class ImportEmailTemplatesCommand extends Command
{
    protected $signature = 'kanvas:import-email-templates
                            {app_id : The app ID to import templates into}
                            {json_file : Absolute path to the PHPMyAdmin JSON export file}';

    protected $description = 'Import email templates from a PHPMyAdmin JSON export into a specific app';

    public function handle(): void
    {
        $appId = (int) $this->argument('app_id');
        $jsonFile = $this->argument('json_file');

        if (! file_exists($jsonFile)) {
            error("File not found: {$jsonFile}");
            return;
        }

        $app = Apps::find($appId);
        if (! $app) {
            error("App with ID {$appId} not found.");
            return;
        }

        $data = json_decode(file_get_contents($jsonFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error('Invalid JSON file: ' . json_last_error_msg());
            return;
        }

        $templates = [];
        foreach ($data as $item) {
            if (($item['type'] ?? '') === 'table' && $item['name'] === 'email_templates') {
                $templates = $item['data'];
                break;
            }
        }

        if (empty($templates)) {
            error('No email_templates table found in JSON file.');
            return;
        }

        $now = now()->toDateTimeString();
        $inserted = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            $exists = DB::table('email_templates')
                ->where('apps_id', $appId)
                ->where('name', $template['name'])
                ->where('companies_id', 0)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::table('email_templates')->insert([
                'users_id' => 1,
                'companies_id' => 0,
                'apps_id' => $appId,
                'name' => $template['name'],
                'title' => $template['title'],
                'subject' => $template['subject'],
                'parent_template_id' => 0,
                'template' => $template['template'],
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
            ]);

            $inserted++;
        }

        info("App: {$app->name} (ID: {$appId})");
        info("Templates inserted: {$inserted}");
        info("Templates skipped (already exist): {$skipped}");
    }
}
