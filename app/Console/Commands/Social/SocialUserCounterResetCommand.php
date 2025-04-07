<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UserAppRepository;

class SocialUserCounterResetCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-social:reset-counter {app_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the social counter for a specific app';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info('Resetting social counter for app: ' . $this->argument('app_id'));

        UserAppRepository::getAllAppUsers($app)->chunk(100, function ($users) use ($app) {
            foreach ($users as $user) {
                $this->info('Resetting social counter for user: ' . $user->getId());
                $user->resetSocialCount($app);
            }
        });
    }
}
