<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
use Illuminate\Database\Eloquent\Builder;

class MemodMailCampaignPromptMineCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promptmine:campaign-mail {apps_id} {email_template_name} {subject}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'send specific email to all users of an app';

    /**
     * Execute the console command.
     * 
     * @todo This should be a cron
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        // Run your raw SQL query with LIMIT and OFFSET
        $memodUsers = DB::connection('memod')
        ->table('users')
        ->select('email', 'user_active', 'is_deleted')
        ->where('user_active', StateEnums::YES->getValue())
        ->where('is_deleted', StateEnums::NO->getValue())
        ->orderBy('id')
        ->chunk(100, function ($memodUsers) use ($app) {
            foreach ($memodUsers as $memodUser) {

                $user = UsersAssociatedApps::fromApp($app)
                ->select('email', 'user_active', 'is_deleted')
                ->where('email', $memodUser->email)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->first();

                if (!$user) {
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

        });
    }
}
