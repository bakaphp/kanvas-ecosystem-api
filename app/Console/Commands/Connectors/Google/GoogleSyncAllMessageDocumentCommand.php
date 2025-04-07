<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Actions\SyncMessageToDocumentAction;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class GoogleSyncAllMessageDocumentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:google-sync-all-message-document {app_id} {company_id} {user_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send all messages to google recommendation as documents';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $syncMessageToDocumentAction = new SyncMessageToDocumentAction($app, $company, $user);

        try {
            $results = $syncMessageToDocumentAction->execute(MessageType::getById($app->get('social-user-message-filter-message-type')));
        } catch (ModelNotFoundException $e) {
            $results = $syncMessageToDocumentAction->execute();
        }

        $this->info(json_encode($results, JSON_PRETTY_PRINT) . ' Messages sent to google recommendation as documents.');

        return;
    }
}
