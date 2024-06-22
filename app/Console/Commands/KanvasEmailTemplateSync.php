<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Console\Command;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;

class KanvasEmailTemplateSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:email-template-sync {app_id} {user_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync email templates from the app to the email service provider';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appId = $this->argument('app_id');
        $app = Apps::getById($appId);
        $user = Users::getById($this->argument('user_id'));

        if (! $app) {
            $this->error("App with ID $appId not found.");

            return 1;
        }

        $this->info("Sync email templates from the app: {$app->name}");
        $this->newLine();

        $defaultTemplate = Templates::notDeleted()->fromApp($app)->where('parent_template_id', 0)->first();

        if (! $defaultTemplate) {
            $this->error('Default template not found.');
            $this->syncTemplates($app, $user);

            return 1;
        }

        if ($this->confirm('Do you want to sync the email templates? This will overwrite the existing email templates.')) {
            $this->syncTemplates($app, $user);
            $this->info('Email templates have been synced.');
        } else {
            $this->info('Email templates syncing has been canceled.');
        }

        return 0;
    }

    /**
     * Sync the email templates.
     */
    protected function syncTemplates(Apps $app, UserInterface $user): void
    {
        $syncEmailTemplate = new SyncEmailTemplateAction($app, $user);
        $syncEmailTemplate->execute();
    }
}
