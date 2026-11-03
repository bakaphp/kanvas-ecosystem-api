<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

/**
 * Creates the receiver for EmailDeliveryWebhookJob and points the company's sending domain at it for
 * every delivery event. `opened` is left to kanvas:mailgun-setup-email-opened-webhook.
 */
class SetupEmailDeliveryWebhooksCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * Mailgun's webhook ids; a failure is reported under one of the two `*_fail` ids by severity.
     * `accepted` is left out: the send itself files the attempt as accepted, so the event adds nothing.
     */
    private const array EVENTS = [
        'delivered',
        'permanent_fail',
        'temporary_fail',
        'complained',
        'unsubscribed',
    ];

    protected $signature = 'kanvas:mailgun-setup-delivery-webhooks
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID that owns the receiver}';

    protected $description = 'Creates the Mailgun delivery-status receiver and registers it with Mailgun for the company\'s sending domain.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('user_id'));

        $action = Action::firstOrCreate(
            ['model_name' => EmailDeliveryWebhookJob::class],
            ['name' => class_basename(EmailDeliveryWebhookJob::class)],
        );

        $receiver = ReceiverWebhook::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'action_id' => $action->getId(),
                'name' => 'Mailgun Email Delivery Status',
                'is_deleted' => 0,
            ],
            [
                'users_id' => $user->getId(),
                'description' => 'Receives Mailgun\'s delivery events and records them on the outbound email.',
                'is_active' => true,
            ],
        );

        $domain = new AgentMailboxService()->domainForTenant($app, $company);
        $client = new Client($app);

        foreach (self::EVENTS as $event) {
            $client->createOrUpdateWebhook($domain, $event, $receiver->getUrl());
        }

        $this->info('Mailgun delivery webhooks (' . implode(', ', self::EVENTS) . ') registered for domain ' . $domain . '.');
        $this->info('Receiver URL: ' . $receiver->getUrl());

        return self::SUCCESS;
    }
}
