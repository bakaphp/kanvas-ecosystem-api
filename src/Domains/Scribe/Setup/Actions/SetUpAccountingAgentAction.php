<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Actions;

use Database\Seeders\ScribeSowTemplateSeeder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Connectors\Mailgun\Actions\EnsureAccountingInboxAction;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum as MailgunCustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\Scribe\Approvals\Actions\SeedEcfApprovalPoliciesAction;
use Kanvas\Scribe\Approvals\Actions\SeedScribeApprovalPoliciesAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Kanvas\Scribe\Quotes\Sow\Services\SowMessageTypeService;
use Kanvas\Scribe\Setup\Enums\AccountingSetupConfigurationEnum;
use Kanvas\Scribe\Setup\Enums\AccountingSetupStepEnum;
use Kanvas\Scribe\Setup\Enums\AccountingSetupStepStatusEnum;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Everything an AP/AR agent needs from the company, created in one idempotent pass (plan §13.4 A, C, E):
 * ledger for the country, rolling fiscal periods, approval policies with a default approver (plus the
 * e-CF policies for a DR company), the accounting inbox, open mailboxes for the AP/AR agents, and the
 * document actions + dunning cadence invoices are sent and chased with.
 *
 * Every step is an "ensure": it creates what is missing and leaves what exists, so a re-run is how the
 * setup resumes after a failed step or a credential added later. Each step's outcome is saved on the
 * company (AccountingSetupStateService) as soon as it finishes, with the step now running, so the setup
 * screen follows it live and a run that dies mid-way leaves what it finished; a failed step never stops
 * the next one.
 *
 * Entry points: onboarding's post-provision hook, HireAgentAction for an AP/AR agent, the
 * run_accounting_setup agent tool and `kanvas-scribe:setup-accounting` (support only).
 */
class SetUpAccountingAgentAction
{
    /** Agent custom field: the setup already opened this agent's mailbox once; never again. */
    public const string MAILBOX_ACCESS_DEFAULTED = 'scribe_mailbox_access_defaulted';

    private readonly AccountingSetupStateService $states;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly ?Users $approver = null,
        private readonly ?string $countryCode = null,
    ) {
        $this->states = new AccountingSetupStateService();
    }

    /**
     * @return array{ran_at: string, country: string, approver_users_id: int, steps: array<string, array<string, mixed>>}
     */
    public function execute(): array
    {
        $country = $this->country();
        $approver = $this->resolveApprover();
        $this->states->setDefaultApprover($this->company, $approver);

        $steps = [];
        foreach ($this->steps($country, $approver) as $step => $work) {
            $this->checkpoint(
                $country,
                $approver,
                $steps,
                $step
            );
            $steps[$step] = $this->run($step, $work);
        }

        $state = [
            'ran_at' => Carbon::now()->toIso8601String(),
            'country' => $country,
            'approver_users_id' => $approver->getId(),
            'steps' => $steps,
        ];
        $this->states->save($this->company, $state);
        $this->recordRun($state);
        $this->syncOnboardingPlan();

        return $state;
    }

    /**
     * The country stated, else the company's e-invoicing regime (its provider's country before its own: a company
     * connected to a DR provider is Dominican even with no country set), else its own country, else US.
     */
    public function country(): string
    {
        $country = $this->countryCode
            ?: FiscalRegimes::forCompany($this->company)?->country()
            ?: $this->company->countryCode()
            ?: 'US';

        return strtoupper(trim($country));
    }

    /**
     * @return array<string, callable(): array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}>
     */
    private function steps(string $country, Users $approver): array
    {
        $ledger = new SeedLedgerAction(
            app: $this->app,
            company: $this->company,
            user: $approver,
            countryCode: $country,
        );

        return [
            AccountingSetupStepEnum::LEDGER->value => fn (): array => $this->ensureLedger($ledger),
            AccountingSetupStepEnum::FISCAL_PERIODS->value => fn (): array => [
                AccountingSetupStepStatusEnum::DONE,
                ['months_opened' => $ledger->openPeriods()],
            ],
            AccountingSetupStepEnum::APPROVAL_POLICIES->value => fn (): array => $this->ensureApprovalPolicies($approver),
            AccountingSetupStepEnum::ECF_POLICIES->value => fn (): array => $this->ensureEcfPolicies($country, $approver),
            AccountingSetupStepEnum::INBOX->value => fn (): array => $this->ensureInbox($approver),
            AccountingSetupStepEnum::AGENT_MAILBOXES->value => fn (): array => $this->openAgentMailboxes(),
            AccountingSetupStepEnum::DEFAULTS->value => fn (): array => $this->ensureDefaults(),
            AccountingSetupStepEnum::COLLECTIONS_DEFAULTS->value => fn (): array => $this->ensureCollectionsDefaults($approver),
            AccountingSetupStepEnum::SOW->value => fn (): array => $this->ensureSow(),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private function checkpoint(
        string $country,
        Users $approver,
        array $steps,
        string $runningStep
    ): void {
        $this->states->save($this->company, [
            'country' => $country,
            'approver_users_id' => $approver->getId(),
            'running_step' => $runningStep,
            'steps' => $steps,
        ]);
    }

    /**
     * @param callable(): array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>} $work
     * @return array<string, mixed>
     */
    private function run(string $step, callable $work): array
    {
        try {
            [$status, $detail] = $work();
        } catch (Throwable $e) {
            report($e);
            [$status, $detail] = [AccountingSetupStepStatusEnum::FAILED, ['error' => $e->getMessage()]];
        }

        return ['status' => $status->value, 'at' => Carbon::now()->toIso8601String()] + $detail;
    }

    /**
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureLedger(SeedLedgerAction $ledger): array
    {
        // The ERP's chart wins; seeding ours beside it would split postings across two charts.
        $erp = new GlOwnershipService()->externalGlSource($this->company);
        if ($erp !== null) {
            return [AccountingSetupStepStatusEnum::SKIPPED, ['reason' => 'erp_owns_gl', 'erp' => $erp->value]];
        }

        return [AccountingSetupStepStatusEnum::DONE, ['accounts_inserted' => $ledger->seedChartOfAccounts()]];
    }

    /**
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureApprovalPolicies(Users $approver): array
    {
        $results = new SeedScribeApprovalPoliciesAction(
            app: $this->app,
            company: $this->company,
            defaultApprover: $approver,
        )->execute();

        return [AccountingSetupStepStatusEnum::DONE, [
            'policies' => array_map(
                fn (array $result): array => [
                    'approval_type' => $result['approval_type'],
                    'id' => $result['policy']->getId(),
                    'created' => $result['created'],
                ],
                $results,
            ),
        ]];
    }

    /**
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureEcfPolicies(string $country, Users $approver): array
    {
        if (FiscalRegimes::find($country) === null) {
            return [AccountingSetupStepStatusEnum::SKIPPED, ['reason' => 'no_e_invoicing_regime', 'country' => $country]];
        }

        $results = new SeedEcfApprovalPoliciesAction(
            app: $this->app,
            company: $this->company,
            defaultApprover: $approver,
        )->execute();

        return [AccountingSetupStepStatusEnum::DONE, [
            'policies' => array_map(
                fn (array $result): array => ['approval_type' => $result['approval_type'], 'id' => $result['policy']->getId()],
                $results,
            ),
        ]];
    }

    /**
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureInbox(Users $approver): array
    {
        $owner = new AccountingAgentLocatorService()->payables($this->app, $this->company)?->user ?? $approver;
        $inbox = new EnsureAccountingInboxAction($this->app, $this->company, $owner)->execute();

        return [AccountingSetupStepStatusEnum::DONE, $inbox];
    }

    /**
     * Vendors write to the AP/AR agents' own mailboxes too; restricted, their mail is refused without a
     * word. Opened once: an admin who restricts it later is not overruled by the next run.
     *
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function openAgentMailboxes(): array
    {
        $mailboxes = new AgentMailboxService();
        $opened = [];

        foreach (new AccountingAgentLocatorService()->all($this->app, $this->company) as $agent) {
            if (! $mailboxes->hasMailbox($agent) || $agent->get(self::MAILBOX_ACCESS_DEFAULTED)) {
                continue;
            }

            $agent->set(MailgunCustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::OPEN->value);
            $agent->set(self::MAILBOX_ACCESS_DEFAULTED, true);
            $opened[] = $agent->getId();
        }

        return [AccountingSetupStepStatusEnum::DONE, ['opened_agent_ids' => $opened]];
    }

    /**
     * Defaults the company can change later in settings (§10 item 16); a value already set is theirs.
     *
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureDefaults(): array
    {
        $key = AccountingSetupConfigurationEnum::AI_EXPENSE_CODING->value;
        $set = [];
        if ($this->company->get($key) === null) {
            $this->company->set($key, true);
            $set[] = $key;
        }

        return [AccountingSetupStepStatusEnum::DONE, ['set' => $set]];
    }

    /**
     * The document actions invoices and quotes are sent and chased through, and the default dunning
     * cadence (decision 23). A cadence the company already set is left alone.
     *
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureCollectionsDefaults(Users $approver): array
    {
        new DocumentActionsSetup($this->app, $this->company->user ?? $approver, $this->company)->run();

        $seeded = ! is_array($this->company->get(DunningSchedule::SETTING));
        if ($seeded) {
            $this->company->set(DunningSchedule::SETTING, DunningSchedule::defaults());
        }

        return [AccountingSetupStepStatusEnum::DONE, ['dunning_seeded' => $seeded]];
    }

    /**
     * @return array{0: AccountingSetupStepStatusEnum, 1: array<string, mixed>}
     */
    private function ensureSow(): array
    {
        $type = SowMessageTypeService::ensure($this->app);

        return [
            AccountingSetupStepStatusEnum::DONE,
            [
                'message_type_id' => $type->getId(),
                'template_written' => ScribeSowTemplateSeeder::forApp($this->app->getId()),
            ],
        ];
    }

    private function resolveApprover(): Users
    {
        return $this->approver ?? $this->states->approverOrOwner($this->company);
    }

    /**
     * A company that came through onboarding has a setup plan; its tasks close as their requirement
     * turns ready. Best-effort: an open task is not a failed setup.
     */
    private function syncOnboardingPlan(): void
    {
        try {
            $row = CompanyOnboarding::forCompany($this->app, $this->company);
            if ($row !== null) {
                new SyncAccountingSetupPlanAction($row)->execute();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordRun(array $state): void
    {
        try {
            new AppendEventAction(
                new EventData(
                    app: $this->app,
                    company: $this->company,
                    sourceDomain: 'Scribe',
                    eventType: 'scribe.accounting.setup_ran',
                    sourceEntityType: Companies::class,
                    sourceEntityId: $this->company->getId(),
                    actorType: 'User',
                    actorId: $state['approver_users_id'],
                    payload: $state,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
