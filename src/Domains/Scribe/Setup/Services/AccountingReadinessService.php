<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Services;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Services\ConnectorStatusMap;
use Kanvas\Connectors\Gmail\Enums\ConfigurationEnum as GmailConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AccountingInboxService;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Services\AuthorizedRangeNumbering;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\GlOwnershipService;
use Kanvas\Scribe\Ledger\Services\PeriodCloseService;
use Kanvas\Scribe\Setup\DataTransferObject\ReadinessItem;
use Kanvas\Scribe\Setup\Enums\ReadinessRequirementEnum;
use Kanvas\Scribe\Setup\Enums\ReadinessStatusEnum;

/**
 * What an AP/AR agent can rely on right now, one line per requirement: ready, missing (who fixes it and
 * how) or degraded. The UI checklist, the agents' prompt line, the tools they get and the onboarding
 * setup plan all read this one answer (plan §13.4 G), so they cannot disagree.
 */
class AccountingReadinessService
{
    private const string ADMIN = 'A company admin';
    private const string PLATFORM = 'Kanvas support';

    /**
     * @return array<string, ReadinessItem> keyed by requirement
     */
    public function check(Apps $app, Companies $company): array
    {
        $items = [
            $this->ledger($app, $company),
            $this->fiscalPeriods($app, $company),
            $this->inbox($app, $company),
            $this->approver($app, $company),
            $this->bank($app, $company),
            $this->erp($company),
        ];

        $regime = FiscalRegimes::forCompany($company);
        if ($regime !== null) {
            $items[] = $this->ecfProvider($company, $regime);
            if ($this->loadsAuthorizedRanges($company)) {
                $items[] = $this->ecfRanges($app, $company, $regime);
            }
        }

        $items[] = $this->slack($app, $company);

        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->requirement->value] = $item;
        }

        return $keyed;
    }

    public function isReady(Apps $app, Companies $company, ReadinessRequirementEnum $requirement): bool
    {
        return ($this->check($app, $company)[$requirement->value] ?? null)?->isReady() ?? false;
    }

    /**
     * The one generated line an AP/AR agent reads every turn.
     */
    public function promptLine(Apps $app, Companies $company): string
    {
        $parts = [];
        foreach ($this->check($app, $company) as $item) {
            if ($item->isReady()) {
                $parts[] = "{$item->requirement->label()}: {$item->detail}";

                continue;
            }

            if ($item->optional) {
                $parts[] = "{$item->requirement->label()}: not connected";

                continue;
            }

            $fix = $item->fixBy !== null ? " ({$item->fixBy}: {$item->fixHow})" : '';
            $parts[] = "{$item->requirement->label()}: " . strtoupper($item->status->value) . " — {$item->detail}{$fix}";
        }

        return '## Your setup right now' . "\n"
            . implode('; ', $parts) . '.' . "\n"
            . 'When asked to do something that depends on a line that is not ready, say exactly what is missing '
            . 'and who fixes it instead of attempting it.';
    }

    public function gmailConnected(Apps $app): bool
    {
        return trim((string) $app->get(GmailConfigurationEnum::REFRESH_TOKEN->value)) !== '';
    }

    public function issuesEcf(Companies $company): bool
    {
        return new EcfProviderResolver()->issuesEcf($company);
    }

    /**
     * Whether the company's regime numbers from ranges its authority authorizes, which are loaded by hand.
     */
    public function loadsAuthorizedRanges(Companies $company): bool
    {
        return FiscalRegimes::forCompany($company)?->numbering() instanceof AuthorizedRangeNumbering;
    }

    private function ledger(Apps $app, Companies $company): ReadinessItem
    {
        $erp = new GlOwnershipService()->externalGlSource($company);
        if ($erp !== null) {
            return $this->ready(ReadinessRequirementEnum::LEDGER, "{$erp->documentSource()} owns the books; Kanvas mirrors them");
        }

        $accounts = Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->count();

        return $accounts > 0
            ? $this->ready(ReadinessRequirementEnum::LEDGER, "chart of accounts ready ({$accounts} accounts)")
            : new ReadinessItem(
                requirement: ReadinessRequirementEnum::LEDGER,
                status: ReadinessStatusEnum::MISSING,
                detail: 'no chart of accounts yet',
                fixBy: self::ADMIN,
                fixHow: 'run the accounting setup',
            );
    }

    private function fiscalPeriods(Apps $app, Companies $company): ReadinessItem
    {
        $period = new PeriodCloseService()->findPeriodFor($app->getId(), $company->getId(), Carbon::today());

        if ($period === null) {
            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::FISCAL_PERIODS,
                status: ReadinessStatusEnum::MISSING,
                detail: 'no fiscal period covers today',
                fixBy: self::ADMIN,
                fixHow: 'run the accounting setup, it opens this year and the next',
            );
        }

        $status = $period->status instanceof FiscalPeriodStatusEnum ? $period->status : FiscalPeriodStatusEnum::tryFrom((string) $period->status);

        return $status === FiscalPeriodStatusEnum::OPEN
            ? $this->ready(ReadinessRequirementEnum::FISCAL_PERIODS, 'the current month is open')
            : new ReadinessItem(
                requirement: ReadinessRequirementEnum::FISCAL_PERIODS,
                status: ReadinessStatusEnum::DEGRADED,
                detail: 'the current month is closed, nothing dated today can post',
                fixBy: self::ADMIN,
                fixHow: 'reopen the period or date documents into an open one',
            );
    }

    private function inbox(Apps $app, Companies $company): ReadinessItem
    {
        $receiver = new AccountingInboxService()->receiverFor($app, $company);
        $address = $receiver !== null ? AccountingInboxService::addressOn($receiver) : null;

        if ($address === null) {
            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::INBOX,
                status: ReadinessStatusEnum::MISSING,
                detail: 'no accounting inbox yet',
                fixBy: self::ADMIN,
                fixHow: 'run the accounting setup',
            );
        }

        return AccountingInboxService::routeOn($receiver) !== null
            ? $this->ready(ReadinessRequirementEnum::INBOX, "forward invoices to {$address}")
            : new ReadinessItem(
                requirement: ReadinessRequirementEnum::INBOX,
                status: ReadinessStatusEnum::DEGRADED,
                detail: "{$address} exists but mail is not forwarded to it yet",
                fixBy: self::PLATFORM,
                fixHow: 'configure the Mailgun key and signing key, then run the accounting setup again',
            );
    }

    private function approver(Apps $app, Companies $company): ReadinessItem
    {
        $approver = new AccountingSetupStateService()->defaultApprover($company);
        if ($approver !== null) {
            $name = $approver->fullName() ?: (string) $approver->email;

            return $this->ready(ReadinessRequirementEnum::APPROVER, "{$name} approves bills and invoices when the vendor or customer names nobody");
        }

        $hasPolicies = ApprovalPolicy::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('approval_type', 'approve_bill')
            ->notDeleted()
            ->exists();

        return new ReadinessItem(
            requirement: ReadinessRequirementEnum::APPROVER,
            status: $hasPolicies ? ReadinessStatusEnum::DEGRADED : ReadinessStatusEnum::MISSING,
            detail: $hasPolicies ? 'no default approver; the company owner signs what no vendor approver covers' : 'no approval policies and no approver',
            fixBy: self::ADMIN,
            fixHow: 'name the default approver in the accounting setup',
        );
    }

    private function bank(Apps $app, Companies $company): ReadinessItem
    {
        $coverage = new BankStatementCoverageService();
        $accounts = $coverage->activeAccounts($app, $company);

        if ($accounts->isEmpty()) {
            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::BANK,
                status: ReadinessStatusEnum::MISSING,
                detail: 'no bank account, so payments cannot be matched',
                fixBy: self::ADMIN,
                fixHow: 'connect Mercury, or send a bank statement (CSV or PDF) to the AP agent',
            );
        }

        $behind = $accounts->filter(fn ($account): bool => $coverage->isBehind($account));
        if ($behind->isNotEmpty()) {
            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::BANK,
                status: ReadinessStatusEnum::DEGRADED,
                detail: 'behind on ' . $behind->pluck('account_name')->implode(', '),
                fixBy: self::ADMIN,
                fixHow: 'send the latest statement for those accounts',
            );
        }

        return $this->ready(ReadinessRequirementEnum::BANK, $accounts->count() . ' account(s), up to date');
    }

    private function erp(Companies $company): ReadinessItem
    {
        $erp = new GlOwnershipService()->externalGlSource($company);

        return new ReadinessItem(
            requirement: ReadinessRequirementEnum::ERP,
            status: ReadinessStatusEnum::READY,
            detail: $erp !== null
                ? "{$erp->documentSource()} connected"
                : 'none connected, Kanvas is the ledger; say so if asked about data from another accounting system',
            optional: true,
        );
    }

    private function ecfProvider(Companies $company, FiscalRegimeInterface $regime): ReadinessItem
    {
        $provider = new EcfProviderResolver()->providerKey($company);
        $authority = $regime->authority();

        if ($provider === null) {
            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::ECF_PROVIDER,
                status: ReadinessStatusEnum::MISSING,
                detail: 'no electronic-invoicing provider, so no fiscal invoice can be issued',
                fixBy: self::ADMIN,
                fixHow: "connect the {$authority} e-invoicing provider in integrations",
            );
        }

        if ($regime->issuerTaxId($company) === null) {
            $taxId = ($regime->taxIdTypes()[0] ?? null)?->label() ?? 'tax id';

            return new ReadinessItem(
                requirement: ReadinessRequirementEnum::ECF_PROVIDER,
                status: ReadinessStatusEnum::DEGRADED,
                detail: "{$provider} connected but no issuer {$taxId} is configured, so no fiscal invoice can be issued",
                fixBy: self::ADMIN,
                fixHow: "reconnect {$provider} with the {$taxId} {$authority} authorized for e-invoicing",
            );
        }

        return $this->ready(ReadinessRequirementEnum::ECF_PROVIDER, "{$provider} connected");
    }

    private function ecfRanges(Apps $app, Companies $company, FiscalRegimeInterface $regime): ReadinessItem
    {
        $authority = $regime->authority();
        $ready = [];

        foreach ($regime->readinessDocumentTypes() as $type) {
            $series = $type->series();
            $sequence = new DocumentNumberAllocatorService()->find(
                $app->getId(),
                $company->getId(),
                DocumentTypeEnum::forFiscal($type),
            );

            if ($sequence === null || ! $sequence->isUsableRange()) {
                return new ReadinessItem(
                    requirement: ReadinessRequirementEnum::ECF_RANGES,
                    status: ReadinessStatusEnum::MISSING,
                    detail: "no usable {$series} range from {$authority}",
                    fixBy: self::ADMIN,
                    fixHow: "give the accounting assistant the {$series} range {$authority} authorized (first and last number, expiry)",
                );
            }

            $reasons = $sequence->attentionReasons();
            if ($reasons !== []) {
                return new ReadinessItem(
                    requirement: ReadinessRequirementEnum::ECF_RANGES,
                    status: ReadinessStatusEnum::DEGRADED,
                    detail: "the {$series} range needs attention (" . implode(', ', $reasons) . ", {$sequence->remaining()} left)",
                    fixBy: self::ADMIN,
                    fixHow: "request the next {$series} range from {$authority} and give it to the accounting assistant",
                );
            }

            $ready[] = "{$series} range valid, {$sequence->remaining()} left";
        }

        return $this->ready(ReadinessRequirementEnum::ECF_RANGES, implode('; ', $ready));
    }

    private function slack(Apps $app, Companies $company): ReadinessItem
    {
        $connected = ConnectorStatusMap::connected($app, $company, ConnectorStatusMap::SLACK_WORKSPACE);

        return new ReadinessItem(
            requirement: ReadinessRequirementEnum::SLACK,
            status: $connected ? ReadinessStatusEnum::READY : ReadinessStatusEnum::MISSING,
            detail: $connected ? 'approvers are reached on Slack' : 'not connected, approvers are emailed',
            fixBy: $connected ? null : self::ADMIN,
            fixHow: $connected ? null : 'connect the Slack workspace',
            optional: true,
        );
    }

    private function ready(ReadinessRequirementEnum $requirement, string $detail): ReadinessItem
    {
        return new ReadinessItem($requirement, ReadinessStatusEnum::READY, $detail);
    }
}
