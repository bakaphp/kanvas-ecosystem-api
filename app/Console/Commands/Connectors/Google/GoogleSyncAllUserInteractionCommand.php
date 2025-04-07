<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Actions\SyncUserInteractionToEventAction;
use Kanvas\Users\Models\Users;

class GoogleSyncAllUserInteractionCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:google-sync-all-user-interaction {app_id} {company_id} {user_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all user interaction to google recommendation as Events';

    /**
     * Execute the console command.
     *
     * @return mixed4231
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $syncUserInteractionToEvent = new SyncUserInteractionToEventAction($app, $company, $user);
        $results = $syncUserInteractionToEvent->execute();

        $this->info(json_encode($results, JSON_PRETTY_PRINT) . ' User Interactions sent to google recommendation as Events');

        return;
    }
}
