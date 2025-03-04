<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema as FacadesSchema;
use Kanvas\Enums\AppEnums;

class KanvasSyncUserMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:sync-user-messages';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync user messages table with messages from the people a user follows';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (FacadesSchema::hasTable('migration')) {
            $this->info('Some migrations have already been run. Meaning the ecosystem is already setup, Skipping setup.');

            return;
        }

        $commands = [
            'migrate',
            'migrate --path database/migrations/Inventory/ --database inventory',
            'migrate --path database/migrations/Social/ --database social',
            'migrate --path database/migrations/Guild/ --database crm',
            'migrate --path database/migrations/Workflow/ --database workflow',
            'migrate --path database/migrations/Souk/ --database commerce',
            'migrate --path vendor/laravel-workflow/laravel-workflow/src/migrations/ --database workflow',
            'migrate --path database/migrations/ActionEngine/ --database action_engine',
            'migrate --path database/migrations/Subscription/ --database mysql',
            'migrate --path database/migrations/Event/ --database event',
            'db:seed',
            'db:seed --class=Database\\\Seeders\\\GuildSeeder --database crm',
            'kanvas:create-role Admin',
            'kanvas:create-role Users',
            'kanvas:create-role Agents',
            'kanvas:filesystem-setup',
            'kanvas:create-workflow-status',
        ];

        foreach ($commands as $command) {
            $this->line('Running command: ' . $command);
            $exitCode = Artisan::call($command);

            if ($exitCode !== 0) {
                $this->error('Command failed: ' . $command);

                break;
            }
        }

        $this->info('All commands executed successfully - Welcome to Kanvas Ecosystem ' . AppEnums::VERSION->getValue());

        return;
    }
}
