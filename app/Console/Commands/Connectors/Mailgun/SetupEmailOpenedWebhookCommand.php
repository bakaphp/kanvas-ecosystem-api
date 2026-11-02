<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Webhooks\EmailOpenedWebhookJob;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

/**
 * One-time per-company setup: creates the Kanvas receiver for EmailOpenedWebhookJob and, unlike the
 * IAP webhook setup (which just prints a URL for a human to paste into Apple/Google's dashboard),
 * also calls Mailgun's own API to register it — Mailgun's domain-webhook endpoint makes that possible
 * where Apple/Google's don't.
 */
class SetupEmailOpenedWebhookCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mailgun-setup-email-opened-webhook
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID that owns the receiver}';

    protected $description = 'Creates the Mailgun "opened" event receiver and registers it with Mailgun for the company\'s sending domain.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('user_id'));

        $action = Action::firstOrCreate(
            ['model_name' => EmailOpenedWebhookJob::class],
            ['name' => class_basename(EmailOpenedWebhookJob::class)],
        );

        $receiver = ReceiverWebhook::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'action_id' => $action->getId(),
                'name' => 'Mailgun Email Opened',
                'is_deleted' => 0,
            ],
            [
                'users_id' => $user->getId(),
                'description' => 'Receives Mailgun\'s "opened" event and records it on the People the email was about.',
                'is_active' => true,
            ],
        );

        $domain = new AgentMailboxService()->domainForTenant($app, $company);

        new Client($app)->createOrUpdateWebhook($domain, 'opened', $receiver->getUrl());

        $this->info('Mailgun "opened" webhook registered for domain ' . $domain . '.');
        $this->info('Receiver URL: ' . $receiver->getUrl());

        return self::SUCCESS;
    }
}
