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

        $users = UsersAssociatedApps::fromApp($app)
            ->where('is_delete', StateEnums::NO)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->chunk(100, function ($users) use ($app) {
                foreach ($users as $user) {
                    //send email to user
                    $this->sendEmail($user, $app);
                }
            });

        foreach ($users as $user) {
            $notification = new Blank(
                $this->argument('email_template_name'),
                ['userFirstname' => $user->firstname],
                ['mail'],
                $user
            );

            $notification->setSubject($this->argument('subject'));
            Notification::route('mail', $user->email)->notify($notification);
            $this->info('Email Successfully sent to: ' . $user->getId() . ' on app: ' . $app->getId());
            $this->newLine();
        }
    }
}