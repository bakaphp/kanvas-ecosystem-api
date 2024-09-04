<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Baka\Enums\StateEnums;
use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\UsersAssociatedApps;

class UserEngagementReminderCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:users-engagement-reminder {app_id?} ';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Simple daily email reminder to get users to engage with the app again';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info('Sending User Engagement Reminder for app ' . $app->name . ' - ' . date('Y-m-d'));
        //get the list of user form UsersAssociatedApps chuck by 100 records
        DB::table('users_associated_apps')
            ->where('apps_id', $app->id) // Assuming 'app_id' is the foreign key
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->orderBy('users_id') // Order by primary or unique key for consistency
            ->chunk(100, function ($users) use ($app) {
                $users = UsersAssociatedApps::hydrate($users->toArray());
                foreach ($users as $user) {
                    $this->sendEmail($user, $app);
                }
            });
    }

    public function sendEmail(UsersAssociatedApps $user, Apps $app): void
    {
        //send email to user
        $this->info('Sending email to user ' . $user->email);

        $lastVisitInDays = Carbon::parse($user->lastvisit)->diffInDays(Carbon::now());
        $this->info('Last visit in days ' . $lastVisitInDays);

        $engagementEmailTemplateConfiguration = $app->get('engagement_email_template') ?? [];

        if (empty($engagementEmailTemplateConfiguration)) {
            $this->info('No email template configuration found');

            return;
        }

        if (empty($engagementEmailTemplateConfiguration[$lastVisitInDays])) {
            $this->info('No email template configuration found for ' . $lastVisitInDays . ' days');

            return;
        }

        $emailTemplate = $engagementEmailTemplateConfiguration[$lastVisitInDays];

        if (! isset($emailTemplate['template'])) {
            $this->info('No email template found for ' . $lastVisitInDays . ' days');

            return;
        }

        $notification = new Blank(
            $emailTemplate['template'],
            [
                'app' => $app,
                'user' => $user->user,
                'config' => $emailTemplate,
            ],
            ['mail'],
            $user
        );

        Notification::route('mail', $user->email)->notify($notification);
        //@todo save it in user activity log on social?
        $this->info('Email sent to ' . $user->email);
    }
}
