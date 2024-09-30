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
        $emailTemplateName = $this->argument('email_template_name');
        $emailSubject = $this->argument('subject');

        if (getenv('TEST_EMAIL_FEATURE')) {
            $userModelEntity = Users::getByEmail(getenv('TEST_EMAIL_FEATURE_ADRESS'));
            $this->sendEmailToUser($userModelEntity, $emailTemplateName, $emailSubject);
            $this->info('Email Successfully sent to: ' . $userModelEntity->getId() . ' on app: ' . $app->getId());
            exit();
        }

        $user = DB::table('users_associated_apps')
        ->where('apps_id', $app->id) // Assuming 'app_id' is the foreign key
        ->where('is_deleted', StateEnums::NO->getValue())
        ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
        ->orderBy('users_id') // Order by primary or unique key for consistency
        ->chunk(100, function ($users) use ($app) {
            foreach ($users as $user) {
                $userModelEntity = Users::getByEmail($user->email);
                $this->sendEmailToUser($userModelEntity, $emailTemplateName, $emailSubject);
                $this->info('Email Successfully sent to: ' . $user->users_id . ' on app: ' . $app->getId());
                $this->newLine();
            }
        });
    }

    /**
     * Send email to user using a custom template
     *
     */
    private function sendEmailToUser(Users $user, string $emailTemplateName, string $emailSubject): void
    {
        $notification = new Blank(
            $emailTemplateName,
            ['userFirstname' => $user->firstname],
            ['mail'],
            $user
        );
        $notification->setSubject($emailSubject);
        Notification::route('mail', $user->email)->notify($notification);
    }
}
