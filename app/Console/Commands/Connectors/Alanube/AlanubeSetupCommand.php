<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Alanube;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Actions\ConnectAlanubeCompanyAction;
use Kanvas\Connectors\Alanube\Actions\OnboardAlanubeCompanyAction;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCertificate;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeCompanyProfile;
use Kanvas\Connectors\Alanube\DataTransferObject\AlanubeWebhookRegistration;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;

/**
 * Connects or onboards a company on Alanube and makes it the company's e-CF provider.
 *
 * - With --certificate-path: onboards a new company at Alanube (`POST /company`) with the DGII certificate,
 *   which is read into memory, sent, and dropped. It is never stored, echoed or logged.
 * - Without it: connects a company that already exists at Alanube (own mode: by token; kanvas mode: by
 *   --alanube-company-id).
 *
 * Secrets are taken from options or prompted without echo; nothing secret is printed.
 */
class AlanubeSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:alanube-setup
                            {app_id}
                            {company_id}
                            {--mode=own : kanvas (under our main company) or own (the tenant\'s Alanube account)}
                            {--token= : Own mode: the tenant\'s Alanube API token (prompted when omitted)}
                            {--alanube-company-id= : Kanvas mode: an existing associated company id}
                            {--environment= : test or production (sets scribe-ecf-environment; unset keeps it)}
                            {--certificate-path= : Onboard: path to the DGII .p12/.pfx certificate}
                            {--certificate-password= : Onboard: certificate password (prompted when omitted)}
                            {--rnc= : Onboard: company RNC}
                            {--name= : Onboard: legal name (defaults to the company name)}
                            {--trade-name= : Onboard: trade name}
                            {--address= : Onboard: fiscal address}
                            {--province= : Onboard: province}
                            {--municipality= : Onboard: municipality}
                            {--email= : Onboard: company email (defaults to the company email)}';

    protected $description = 'Connect or onboard a company on Alanube (DR e-CF) and make it the company e-CF provider.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $mode = AccountModeEnum::tryFrom((string) $this->option('mode'));
        if ($mode === null) {
            $this->error('--mode must be kanvas or own.');

            return self::FAILURE;
        }

        $environment = Str::trimToNull((string) $this->option('environment'));
        if ($environment !== null) {
            if (! in_array($environment, ['test', 'production'], true)) {
                $this->error('--environment must be test or production.');

                return self::FAILURE;
            }

            $company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, $environment);
        }

        // Stored before anything runs: both paths build the Alanube client from company settings.
        if ($mode === AccountModeEnum::OWN) {
            $token = Str::trimToNull((string) $this->option('token'))
                ?? ($company->get(ConfigurationEnum::API_TOKEN->value) === null ? Str::trimToNull((string) $this->secret('Alanube API token')) : null);

            if ($token !== null) {
                $company->setEncrypted(ConfigurationEnum::API_TOKEN->value, $token);
            }
        }

        $company->set(ConfigurationEnum::ACCOUNT_MODE->value, $mode->value);

        $registration = Str::trimToNull((string) $this->option('certificate-path')) !== null
            ? $this->onboard($app, $company)
            : new ConnectAlanubeCompanyAction(
                app: $app,
                company: $company,
                mode: $mode,
                alanubeCompanyId: Str::trimToNull((string) $this->option('alanube-company-id')),
            )->execute();

        if ($registration === null) {
            return self::FAILURE;
        }

        $this->info("Company {$company->getId()} issues e-CF through Alanube ({$mode->value} mode).");
        $this->line('Alanube company id: ' . $company->get(ConfigurationEnum::COMPANY_ID->value));
        $this->line("Webhooks: {$registration->outcome}" . ($registration->verified ? ' (verified)' : ''));

        if ($registration->reason !== null) {
            $this->warn($registration->reason);
        }

        return self::SUCCESS;
    }

    private function onboard(Apps $app, Companies $company): ?AlanubeWebhookRegistration
    {
        $path = (string) $this->option('certificate-path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The certificate file is not readable.');

            return null;
        }

        $rnc = preg_replace('/\D/', '', (string) $this->option('rnc'));
        $address = Str::trimToNull((string) $this->option('address'));

        if ($rnc === null || $rnc === '' || $address === null) {
            $this->error('Onboarding needs --rnc and --address.');

            return null;
        }

        $password = (string) ($this->option('certificate-password') ?: $this->secret('Certificate password'));

        $certificate = new AlanubeCertificate(
            contents: (string) file_get_contents($path),
            password: $password,
            name: pathinfo($path, PATHINFO_FILENAME),
            extension: strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'p12',
        );
        unset($password);

        return new OnboardAlanubeCompanyAction(
            app: $app,
            company: $company,
            profile: new AlanubeCompanyProfile(
                rnc: $rnc,
                companyName: Str::trimToNull((string) $this->option('name')) ?? (string) $company->name,
                address: $address,
                tradeName: Str::trimToNull((string) $this->option('trade-name')),
                province: Str::trimToNull((string) $this->option('province')),
                municipality: Str::trimToNull((string) $this->option('municipality')),
                mail: Str::trimToNull((string) $this->option('email')) ?? Str::trimToNull((string) $company->email),
            ),
            certificate: $certificate,
        )->execute();
    }
}
