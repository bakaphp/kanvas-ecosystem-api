<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Baka\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\PromptMine\ExploreFeed;
use Kanvas\Notifications\Templates\Blank;
use Illuminate\Support\Facades\Notification;

class MailAppUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:SentMailToAll {apps_id} {email_template_name} {subject}';

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
        $users = Users::select('users.id,users.firstname, users.email')
        ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
        ->where('users.is_deleted', StateEnums::NO->getValue())
        ->where('users_associated_apps.apps_id', $app->getId())
        ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
        ->get();

        foreach ($users as $user) {
            $notification = new Blank(
                $this->argument('email_template_name'),
                ['userFirstname' => $user->firstname],
                ['mail'],
                $user
            );

            $notification->setSubject($this->argument('subject'));
            Notification::route('mail', $user->email)->notify($notification);
            $this->info('Email Succesfully sent to: ' . $user->getId() . " on app: " . $app->getId());
            $this->newLine();
        }
    }
}
