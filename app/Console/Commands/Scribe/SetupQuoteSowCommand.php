<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Database\Seeders\ScribeSowTemplateSeeder;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Quotes\Sow\Services\SowMessageTypeService;

class SetupQuoteSowCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:setup-sow
                            {app_id : The app to set up}
                            {--template : Also write an app-level scribe-sow template the tenant can edit}';

    protected $description = 'Create the `sow` message type (with its schema) and optionally the app-level SOW template.';

    public function handle(): int
    {
        $app = Apps::find((int) $this->argument('app_id'));

        if ($app === null) {
            $this->error("App {$this->argument('app_id')} not found.");

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        $type = SowMessageTypeService::ensure($app);
        $this->info("Message type `sow` ready (id {$type->getId()}) for app {$app->getId()}.");

        if ($this->option('template')) {
            $written = ScribeSowTemplateSeeder::forApp($app->getId());
            $this->info($written ? 'App-level scribe-sow template written.' : 'App-level scribe-sow template already exists.');
        }

        return self::SUCCESS;
    }
}
