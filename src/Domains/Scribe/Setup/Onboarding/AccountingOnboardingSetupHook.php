<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Onboarding;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Contracts\OnboardingSetupHookInterface;
use Kanvas\Companies\Onboarding\Enums\RequirementStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Setup\Actions\CreateAccountingAgentTaskAction;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Scribe\Setup\Actions\SyncAccountingSetupPlanAction;
use Kanvas\Scribe\Setup\Enums\AccountingSetupStepStatusEnum;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;

/**
 * `config.setup: "accounting"` on the AP/AR cards. The completing admin is the default approver unless
 * one is already named (decision 24); a bank statement uploaded in the `bank_account` slot is handed to
 * the AP agent to ingest.
 */
final class AccountingOnboardingSetupHook implements OnboardingSetupHookInterface
{
    public const string KEY = 'accounting';
    public const string BANK_ACCOUNT_REQUIREMENT = 'bank_account';

    public function run(Apps $app, Companies $company, Users $user): ?string
    {
        $approverIsNamed = new AccountingSetupStateService()->defaultApprover($company) !== null;

        $state = new SetUpAccountingAgentAction(
            app: $app,
            company: $company,
            approver: $approverIsNamed ? null : $user,
        )->execute();

        $row = CompanyOnboarding::forCompany($app, $company);
        if ($row !== null) {
            $this->handOverUploadedStatement($row, $user);
        }

        $failed = array_keys(array_filter(
            $state['steps'],
            fn (array $step): bool => $step['status'] === AccountingSetupStepStatusEnum::FAILED->value,
        ));

        return $failed === []
            ? null
            : 'Accounting setup incomplete: ' . implode(', ', array_map(
                fn (string $step): string => $step . ' (' . ($state['steps'][$step]['error'] ?? 'failed') . ')',
                $failed,
            ));
    }

    public function syncSetupPlan(CompanyOnboarding $row): int
    {
        return new SyncAccountingSetupPlanAction($row)->execute();
    }

    /**
     * Once per uploaded file: the task id is written back on the answer so a re-run does not hand the
     * same statement over twice.
     */
    private function handOverUploadedStatement(CompanyOnboarding $row, Users $user): void
    {
        $path = 'requirements.' . self::BANK_ACCOUNT_REQUIREMENT;
        $answer = (array) $row->dataGet($path, []);
        $fileId = (int) ($answer['filesystem_id'] ?? 0);

        if (($answer['status'] ?? null) !== RequirementStatusEnum::UPLOADED->value || $fileId <= 0 || ! empty($answer['agent_task_id'])) {
            return;
        }

        $agent = new AccountingAgentLocatorService()->payables($row->app, $row->company);
        if ($agent === null) {
            return;
        }

        $task = new CreateAccountingAgentTaskAction(
            agent: $agent,
            onBehalfOf: $user,
            instruction: "During onboarding {$user->firstname} uploaded a bank statement (filesystem_id {$fileId}). "
                . "Run ingest_bank_statement with filesystem_id {$fileId}. If it needs a bank account or a column "
                . 'mapping, ask them for it; when it lands, tell them what was imported and what is left in Suspense.',
            sourceEntityType: Filesystem::class,
            sourceEntityId: $fileId,
        )->execute();

        $row->dataSet($path . '.agent_task_id', $task->getId());
    }
}
