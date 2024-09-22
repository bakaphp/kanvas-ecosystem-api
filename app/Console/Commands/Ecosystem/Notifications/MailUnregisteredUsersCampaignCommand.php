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
use Illuminate\Database\Eloquent\Builder;

class MailunregisteredUsersCampaignCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:unregistered-users-campaign-mail {apps_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'send specific email to unregistered users from third parties';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);


        $campaignWeek = (int)ceil(now()->day / 7);

        switch ($campaignWeek) {
            case 1:
                $emailTemplateName = 'introducing_prompt_mine';
                $emailSubject = 'Discover Your New Creative Tool: Introducing Prompt Mine';
                break;
            case 2:
                $emailTemplateName = 'get_inspired_with_prompt_mine';
                $emailSubject = 'Get Inspired with Prompt Mine';
                break;
            case 3:
                $emailTemplateName = 'see_what_trending_on_prompt_mine';
                $emailSubject = '{userFirstname}, See What’s Trending on Prompt Mine!';
                break;
            case 4:
                $emailTemplateName = 'elevate_your_ai_experience';
                $emailSubject = 'Elevate Your AI Experience with Prompt Mine';
                break;
        }

        $this->sendMailToUnregistered($app, $emailTemplateName, $emailSubject);
    }

    private function sendMailToUnregistered(Apps $app, string $emailTemplateName, string $emailSubject)
    {
        $memodUsers = DB::connection('third_party')
        ->table('users')
        ->select('email', 'user_active', 'is_deleted')
        ->where('user_active', StateEnums::YES->getValue())
        ->where('is_deleted', StateEnums::NO->getValue())
        ->orderBy('id')
        ->chunk(100, function ($memodUsers) use ($app, $emailTemplateName, $emailSubject) {
            foreach ($memodUsers as $memodUser) {
                $user = UsersAssociatedApps::fromApp($app)
                ->select('email', 'user_active', 'is_deleted')
                ->where('email', $memodUser->email)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->first();

                if (! $user) {
                    $notification = new Blank(
                        $emailTemplateName,
                        ['userFirstname' => $user->firstname],
                        ['mail'],
                        $user
                    );

                    if (strpos($emailSubject, '{userFirstname}')) {
                        $emailSubject = str_replace('{userFirstname}', $user->firstname, $emailSubject);
                    }
                    $notification->setSubject($emailSubject);
                    Notification::route('mail', $user->email)->notify($notification);
                    $this->info('Email Successfully sent to: ' . $user->getId() . ' on app: ' . $app->getId());
                    $this->newLine();
                }
            }
        });
    }
}
