<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Scribe\Setup\DataTransferObject\ReadinessItem;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;
use Kanvas\Users\Models\Users;

/**
 * The accounting setup for a company that did not come through onboarding, and the readiness
 * checklist support reads (`--check` runs nothing, it only prints).
 */
class SetUpAccountingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:setup-accounting
        {app_id}
        {company_id}
        {--approver= : User id of the default approver (default: the one already named, else the company owner)}
        {--country= : ISO 3166-1 alpha-2 override for the company country}
        {--check : Print the readiness checklist without changing anything}';

    protected $description = 'Sets up what the AP/AR agents need (ledger, periods, approvals, inbox) and prints the readiness checklist';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        if (! $this->option('check')) {
            $approverId = (int) $this->option('approver');
            $state = new SetUpAccountingAgentAction(
                app: $app,
                company: $company,
                approver: $approverId > 0 ? Users::getById($approverId) : null,
                countryCode: $this->option('country') ?: null,
            )->execute();

            $this->info("Accounting setup for {$company->name} (country {$state['country']}, approver user {$state['approver_users_id']}):");
            $this->table(
                ['step', 'status', 'detail'],
                array_map(
                    fn (string $step, array $detail): array => [$step, $detail['status'], json_encode(array_diff_key($detail, ['status' => 1, 'at' => 1]))],
                    array_keys($state['steps']),
                    $state['steps'],
                ),
            );
        }

        $this->info('Readiness:');
        $this->table(
            ['requirement', 'status', 'detail', 'who fixes it', 'how'],
            array_map(
                fn (ReadinessItem $item): array => [$item->requirement->value, $item->status->value, $item->detail, $item->fixBy, $item->fixHow],
                array_values(new AccountingReadinessService()->check($app, $company)),
            ),
        );

        return self::SUCCESS;
    }
}
