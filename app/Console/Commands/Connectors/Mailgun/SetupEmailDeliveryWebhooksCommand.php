<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Mailgun;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Support\SmtpRuntimeConfiguration;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Services\DeliveryWebhookReceiverService;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * Points a sending domain's delivery events at its receiver, one per app and domain: run it once per
 * domain, for any company sending on it. `opened` is left to kanvas:mailgun-setup-email-opened-webhook.
 */
class SetupEmailDeliveryWebhooksCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mailgun-setup-delivery-webhooks
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID that owns the receiver if one is created}
                            {--domain= : The Mailgun sending domain; defaults to the domain of the company\'s from email}';

    protected $description = 'Creates the Mailgun delivery-status receiver for a sending domain and registers it with Mailgun.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = UsersRepository::getUserOfCompanyById($company, (int) $this->argument('user_id'));

        $domain = (string) ($this->option('domain') ?: $this->fromEmailDomain($app, $company));
        $receiver = new DeliveryWebhookReceiverService()->ensure($app, $company, $domain, $user->getId());

        $this->info('Mailgun delivery webhooks (' . implode(', ', DeliveryWebhookReceiverService::EVENTS) . ') registered for domain ' . strtolower($domain) . '.');
        $this->info('Receiver URL: ' . $receiver->getUrl());

        return self::SUCCESS;
    }

    /**
     * Company SMTP mail goes out as the company's from email (`tom@mg.salesassist.io`), and Mailgun files
     * its events under that domain. The company's Mailgun domain is the fallback, for agent mailboxes.
     */
    private function fromEmailDomain(Apps $app, Companies $company): string
    {
        $address = (string) new SmtpRuntimeConfiguration($app, $company)->getFromEmail()['address'];

        return str_contains($address, '@')
            ? Str::after($address, '@')
            : new AgentMailboxService()->domainForTenant($app, $company);
    }
}
