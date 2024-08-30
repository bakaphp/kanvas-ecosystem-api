<?php

namespace App\Console\Commands\PromptMine;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Baka\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\PromptMine\ExploreFeed;

class ExploreFeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promptmine:explore-feed {app_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'send explore feed email to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $users = Users::select('users.firstname')
        ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
        ->where('users.is_deleted', StateEnums::NO->getValue())
        ->where('users_associated_apps.apps_id', $app->getId())
        ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
        ->get();

        foreach ($users as $user) {
            $exploreFeedNotification = new ExploreFeed(
                $user,
                [
                    'userFirstname' => $user->firstname
                ]
            );

            $user->notify($exploreFeedNotification);
        }
    }
}
