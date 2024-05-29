<?php

declare(strict_types=1);

namespace Kanvas\Apps\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\HashTableInterface;
use Illuminate\Support\Facades\Config;

class SmtpRuntimeConfiguration
{
    protected string $appSmtp = 'appSmtp';
    protected string $companySmtp = 'companySmtp';
    protected string $defaultSmtp;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->defaultSmtp = config('mail.default');
    }

    /**
     * Load SMTP settings from the given source.
     */
    protected function loadSmtpSettingsFromSource(string $provider, HashTableInterface $source): string
    {
        $fromAddress = $this->getFromEmail();
        $config = [
            'driver' => 'smtp',
            'host' => $source->get('smtp_host'),
            'port' => $source->get('smtp_port'),
            'from' => [
                'address' => $fromAddress['address'],
                'name' => $fromAddress['name'],
            ],
            'encryption' => $source->get('smtp_encryption') ?? 'tls',
            'username' => $source->get('smtp_username'),
            'password' => $source->get('smtp_password'),
            'timeout' => null,
        ];

        Config::set('mail.mailers.' . $provider, $config);

        return $provider;
    }

    protected function resetMailConfig(): void
    {
        Config::set('mail', config('mail.default'));
    }

    /**
     * Load SMTP settings from the app.
     */
    protected function loadAppSettings(): string
    {
        return $this->loadSmtpSettingsFromSource($this->appSmtp, $this->app);
    }

    /**
     * Load SMTP settings from the company config.
     */
    protected function loadCompanySettings(): string
    {
        return $this->loadSmtpSettingsFromSource($this->companySmtp, $this->company);
    }

    /**
     * Determine the source of SMTP settings and load them.
     * Returns the SMTP settings source used.
     */
    public function loadSmtpSettings(): string
    {
        $this->resetMailConfig();

        if ($this->company !== null && $this->company->get('smtp_host')) {
            return $this->loadCompanySettings();
        } elseif ($this->app->get('smtp_host')) {
            return $this->loadAppSettings();
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
