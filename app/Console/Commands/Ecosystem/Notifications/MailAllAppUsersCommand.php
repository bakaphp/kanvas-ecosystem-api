<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Enums\StateEnums;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\Users;
use Illuminate\Support\Facades\DB;

class MailAllAppUsersCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:mail-notification-to-all-app-users {apps_id} {email_template_name} {subject}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'send specific email to all users of an app';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $user = DB::table('users_associated_apps')
        ->where('apps_id', $app->id) // Assuming 'app_id' is the foreign key
        ->where('is_deleted', StateEnums::NO->getValue())
        ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
        ->orderBy('users_id') // Order by primary or unique key for consistency
        ->chunk(100, function ($users) use ($app) {
            foreach ($users as $user) {
                $userModelEntity = Users::getByEmail($user->email);
                $notification = new Blank(
                    $this->argument('email_template_name'),
                    ['userFirstname' => $user->firstname],
                    ['mail'],
                    $userModelEntity
                );
                $notification->setSubject($this->argument('subject'));
                Notification::route('mail', $user->email)->notify($notification);
                $this->info('Email Successfully sent to: ' . $user->users_id . ' on app: ' . $app->getId());
                $this->newLine();
            }
        });
    }
}
