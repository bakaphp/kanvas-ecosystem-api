<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WebPos;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Actions\EnsureWebPosResultReceiverAction;
use Kanvas\Connectors\WebPos\Actions\SetupWebPosAction;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Throwable;

/**
 * The API key is never echoed: pass it with --api-key, or leave it out and type it at the prompt so it
 * stays out of shell history.
 */
class WebPosSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:webpos-setup
                            {app_id}
                            {company_id}
                            {--api-key= : WebPOS API key (prompted when omitted)}
                            {--license= : WebPOS companyLicCod}
                            {--branch= : WebPOS branchCod}
                            {--pos= : WebPOS posCod}
                            {--rnc= : the RNC DGII authorized for e-CF (RncEmisor)}
                            {--environment=test : test | production}';

    protected $description = 'Connect a company to WebPOS for DR e-CF: store the API key, make WebPOS the e-CF provider, verify the key.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $environment = EnvironmentEnum::tryFrom((string) $this->option('environment'));

        if ($environment === null) {
            $this->error('--environment must be test or production.');

            return self::FAILURE;
        }

        $apiKey = (string) ($this->option('api-key') ?: $this->secret('WebPOS API key'));

        try {
            new SetupWebPosAction(
                app: $app,
                company: $company,
                apiKey: $apiKey,
                companyLicense: (string) $this->option('license'),
                branch: (string) $this->option('branch'),
                pos: (string) $this->option('pos'),
                issuerRnc: (string) $this->option('rnc'),
                environment: $environment,
            )->execute();
        } catch (Throwable $e) {
            $this->error("WebPOS setup failed for company {$company->getId()}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("WebPOS connected for company {$company->getId()} ({$environment->value}); API key verified, e-CF provider set to webpos.");

        try {
            $receiver = new EnsureWebPosResultReceiverAction($app, $company, $company->user)->execute();
            $this->line('Result webhook URL to give WebPOS: ' . EnsureWebPosResultReceiverAction::url($receiver));
        } catch (Throwable $e) {
            $this->warn('Result webhook receiver not provisioned: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
