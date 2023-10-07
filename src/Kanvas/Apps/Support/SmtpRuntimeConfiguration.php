<?php

declare(strict_types=1);

namespace Kanvas\Apps\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\HashTableInterface;

class SmtpRuntimeConfiguration
{
    protected string $appSmtp = 'appSmtp';
    protected string $companySmtp = 'companySmtp';
    protected string $defaultSmtp = 'smtp';

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
    }

    /**
     * Load SMTP settings from the given source.
     */
    protected function loadSmtpSettingsFromSource(string $provider, HashTableInterface $source): void
    {
        config([
            "mail.mailers.{$provider}.host" => $source->get('smtp_host'),
            "mail.mailers.{$provider}.port" => $source->get('smtp_port'),
            "mail.mailers.{$provider}.username" => $source->get('smtp_username'),
            "mail.mailers.{$provider}.password" => $source->get('smtp_password'),
            "mail.mailers.{$provider}.encryption" => $source->get('smtp_encryption'),
            'mail.mailers.from.address' => $source->get('from_email_address'),
            'mail.mailers.from.name' => $source->get('from_email_name'),
        ]);
    }

    /**
     * Load SMTP settings from the app.
     */
    protected function loadAppSettings(): void
    {
        $this->loadSmtpSettingsFromSource($this->appSmtp, $this->app);
        config(['mail.default' => $this->appSmtp]);
    }

    /**
     * Load SMTP settings from the company config.
     */
    protected function loadCompanySettings(): void
    {
        $this->loadSmtpSettingsFromSource($this->companySmtp, $this->company);
        config(['mail.default' => $this->companySmtp]);
    }

    /**
     * Determine the source of SMTP settings and load them.
     * Returns the SMTP settings source used.
     */
    public function loadSmtpSettings(): string
    {
        if ($this->company !== null && $this->company->get('smtp_host')) {
            $this->loadCompanySettings();

            return $this->companySmtp;
        } elseif ($this->app->get('smtp_host')) {
            $this->loadAppSettings();

            return $this->appSmtp;
        }

        return $this->defaultSmtp;
    }

    /**
     * Get the 'from' email settings.
     */
    public function getFromEmail(): array
    {
        if ($this->company !== null && $this->company->get('from_email_address')) {
            return [
                'name' => $this->company->get('from_email_name'),
                'address' => $this->company->get('from_email_address'),
            ];
        }

        return [
            'name' => $this->app->get('from_email_name') ?? config('mail.from.name'),
            'address' => $this->app->get('from_email_address') ?? config('mail.from.address'),
        ];
    }
}
