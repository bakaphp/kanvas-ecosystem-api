<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PasoRapido;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Enums\CompanySettingsEnum;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PasoRapidoCompanyLimitsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-paso-rapido:company-limits
        {app_id : App whose defaults are used to resolve the effective limits}
        {company_id : Company to inspect or configure}
        {--block : Block tag verification for this company}
        {--unblock : Lift the block}
        {--reason= : Message shown to users of a blocked company}
        {--max-attempts= : Per-minute cap for this company}
        {--max-daily= : Per-day cap for this company}
        {--ip-max-daily= : Per-day cap per client IP}
        {--ip-max-users= : Distinct users allowed per client IP per day}
        {--sequential-threshold= : Consecutive near-sequential TAGs that trigger a scan block}';

    protected $description = 'Block a company from PasoRapido tag verification and/or override its rate limits. Every limit accepts 0 to disable that check, or "clear" to fall back to the app default. Run with no options to inspect the current state.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        if ($this->option('block') && $this->option('unblock')) {
            $this->error('--block and --unblock are mutually exclusive.');

            return self::FAILURE;
        }

        $this->applyBlock($company);
        $this->applyLimit($company, CompanySettingsEnum::VERIFY_MAX_ATTEMPTS, $this->option('max-attempts'));
        $this->applyLimit($company, CompanySettingsEnum::VERIFY_MAX_DAILY, $this->option('max-daily'));
        $this->applyLimit($company, CompanySettingsEnum::VERIFY_IP_MAX_DAILY, $this->option('ip-max-daily'));
        $this->applyLimit($company, CompanySettingsEnum::VERIFY_IP_MAX_USERS, $this->option('ip-max-users'));
        $this->applyLimit($company, CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD, $this->option('sequential-threshold'));

        $this->report($app, $company);

        return self::SUCCESS;
    }

    private function applyBlock(Companies $company): void
    {
        if ($this->option('unblock')) {
            $company->del(CompanySettingsEnum::VERIFY_BLOCKED->value);
            $company->del(CompanySettingsEnum::VERIFY_BLOCKED_REASON->value);
            info("Unblocked company {$company->name}.");

            return;
        }

        if (! $this->option('block')) {
            return;
        }

        $company->set(CompanySettingsEnum::VERIFY_BLOCKED->value, '1');

        if ($reason = $this->option('reason')) {
            $company->set(CompanySettingsEnum::VERIFY_BLOCKED_REASON->value, $reason);
        }

        warning("Blocked company {$company->name} from PasoRapido tag verification.");
    }

    private function applyLimit(Companies $company, CompanySettingsEnum $setting, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value === 'clear') {
            $company->del($setting->value);
            info("Cleared {$setting->value}; falls back to the app default.");

            return;
        }

        if (! is_numeric($value) || (int) $value < 0) {
            $this->error("{$setting->value} must be 0 or a positive integer, got '{$value}'. Skipped.");

            return;
        }

        $company->set($setting->value, (string) (int) $value);
        info("Set {$setting->value} = " . (int) $value . ((int) $value === 0 ? ' (check disabled)' : ''));
    }

    private function report(Apps $app, Companies $company): void
    {
        $isCorporate = filter_var($company->get('is_corporate'), FILTER_VALIDATE_BOOLEAN);
        $blocked = filter_var($company->get(CompanySettingsEnum::VERIFY_BLOCKED->value), FILTER_VALIDATE_BOOLEAN);

        $rows = [
            ['corporate', $isCorporate ? 'yes' : 'no', $isCorporate ? 'corporate defaults' : 'retail defaults'],
            ['blocked', $blocked ? 'yes' : 'no', $blocked ? ($company->get(CompanySettingsEnum::VERIFY_BLOCKED_REASON->value) ?: 'default message') : '-'],
        ];

        foreach ($this->limitMatrix($isCorporate) as $label => [$companySetting, $appSetting, $default]) {
            $rows[] = [
                $label,
                $company->get($companySetting->value) ?? '-',
                $this->effective($app, $company, $companySetting, $appSetting, $default),
            ];
        }

        $this->table(['Setting', 'Company override', 'Effective'], $rows);
    }

    /**
     * Mirrors PasoRapidoService::resolveLimits() — keep both in sync.
     *
     * @return array<string, array{0: CompanySettingsEnum, 1: ConfigurationEnum, 2: int}>
     */
    private function limitMatrix(bool $isCorporate): array
    {
        return [
            'max per minute' => [
                CompanySettingsEnum::VERIFY_MAX_ATTEMPTS,
                $isCorporate ? ConfigurationEnum::VERIFY_MAX_ATTEMPTS_CORPORATE : ConfigurationEnum::VERIFY_MAX_ATTEMPTS,
                $isCorporate ? 60 : 3,
            ],
            'max per day' => [
                CompanySettingsEnum::VERIFY_MAX_DAILY,
                $isCorporate ? ConfigurationEnum::VERIFY_MAX_DAILY_CORPORATE : ConfigurationEnum::VERIFY_MAX_DAILY,
                $isCorporate ? 500 : 30,
            ],
            'max per day per IP' => [
                CompanySettingsEnum::VERIFY_IP_MAX_DAILY,
                $isCorporate ? ConfigurationEnum::VERIFY_IP_MAX_DAILY_CORPORATE : ConfigurationEnum::VERIFY_IP_MAX_DAILY,
                $isCorporate ? 2000 : 50,
            ],
            'users per IP' => [
                CompanySettingsEnum::VERIFY_IP_MAX_USERS,
                $isCorporate ? ConfigurationEnum::VERIFY_IP_MAX_USERS_CORPORATE : ConfigurationEnum::VERIFY_IP_MAX_USERS,
                $isCorporate ? 100 : 5,
            ],
            'sequential threshold' => [
                CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD,
                $isCorporate ? ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD_CORPORATE : ConfigurationEnum::VERIFY_SEQUENTIAL_THRESHOLD,
                $isCorporate ? 0 : 5,
            ],
        ];
    }

    private function effective(
        Apps $app,
        Companies $company,
        CompanySettingsEnum $companySetting,
        ConfigurationEnum $appSetting,
        int $default
    ): string {
        $source = 'default';
        $value = $default;

        if (is_numeric($appValue = $app->get($appSetting->value))) {
            $source = 'app';
            $value = (int) $appValue;
        }

        if (is_numeric($override = $company->get($companySetting->value))) {
            $source = 'company';
            $value = (int) $override;
        }

        return $value === 0
            ? "disabled ({$source})"
            : "{$value} ({$source})";
    }
}
