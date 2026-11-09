<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Digest\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Services\ApprovalConditionEvaluatorService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * One company's accounting day as plain arrays — the email template, the chat summary and the agent tool all
 * read this, never the models. Counts of what happened come from the ledger (the only place that records
 * *when* something happened); standing positions (overdue, due soon, waiting approvals, coverage) come from
 * the Scribe tables as of the digest date.
 */
class AccountingDigestBuilderService
{
    public const int DUE_SOON_DAYS = 7;
    public const int APPROVER_STATS_DAYS = 30;
    public const string INVOICE_APPROVAL_TYPE = 'approve_invoice';

    private const array LEDGER_EVENTS = [
        'scribe.invoice.issued',
        'scribe.invoice.ecf_accepted',
        'scribe.invoice.ecf_rejected',
        'scribe.invoice.ecf_result_overdue',
        'scribe.invoice.paid',
        'scribe.credit_note.issued',
        'scribe.bill.received_ecf_ingested',
        'scribe.bill.received',
        'scribe.bill.paid',
        'accounting.bill.payment_recorded',
        'accounting.payment.confirmed_by_bank',
        'accounting.payment.unconfirmed_by_bank',
        'accounting.bank_statement.ingested',
        'accounting.bank_statement.rejected',
        'accounting.bank_statement.requested',
        'scribe.ecf_sequence.attention',
    ];

    private readonly Carbon $start;
    private readonly Carbon $end;
    private readonly BankStatementCoverageService $coverage;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Carbon $day,
    ) {
        $this->start = $day->copy()->startOfDay();
        $this->end = $day->copy()->endOfDay();
        $this->coverage = new BankStatementCoverageService();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $events = $this->ledgerCounts();

        $digest = [
            'date' => $this->day->toDateString(),
            'company_name' => $this->company->name,
            'ar' => [
                'invoices_issued' => $events['scribe.invoice.issued'],
                'ecf_accepted' => $events['scribe.invoice.ecf_accepted'],
                'ecf_rejected' => $events['scribe.invoice.ecf_rejected'],
                'ecf_result_overdue' => $events['scribe.invoice.ecf_result_overdue'],
                'credit_notes_issued' => $events['scribe.credit_note.issued'],
                'invoices_sent' => $this->invoicesSent(),
                'engagements' => $this->invoiceEngagements(),
                'invoices_paid' => $events['scribe.invoice.paid'],
                'created_by_source' => $this->invoicesBySource(),
                'collections_due' => $this->openInvoices(overdue: false),
                'overdue' => $this->openInvoices(overdue: true),
            ],
            'ap' => [
                'bills_received' => $this->billsCreated(),
                'received_ecf' => $events['scribe.bill.received_ecf_ingested'],
                'bills_approved' => $events['scribe.bill.received'],
                'bills_paid' => $events['scribe.bill.paid'],
                'payments_recorded' => $events['accounting.bill.payment_recorded'],
                'payments_confirmed' => $events['accounting.payment.confirmed_by_bank'],
                'payments_unconfirmed' => $events['accounting.payment.unconfirmed_by_bank'],
                'bills_due_soon' => $this->billsDueSoon(),
                'open_payment_runs' => $this->openPaymentRuns(),
            ],
            'banking' => [
                'statements_ingested' => $events['accounting.bank_statement.ingested'],
                'statements_rejected' => $events['accounting.bank_statement.rejected'],
                'statements_requested' => $events['accounting.bank_statement.requested'],
                'accounts' => $this->coverage->activeAccounts($this->app, $this->company)
                    ->map(fn (BankAccount $account): array => $this->coverage->describe($account, $this->day))
                    ->values()
                    ->all(),
            ],
            'sequences' => $this->sequencesNeedingAttention(),
            'approvals' => $this->approvals(),
            'issue_ecf' => $this->invoiceApprovalOff(),
        ];

        $digest['has_activity'] = $this->hasActivity($digest, $events);

        return $digest;
    }

    /**
     * Standing lines (coverage, the issue_ecf switch) are shown but do not on their own justify an email:
     * a company that is quiet except for an old setting should not be mailed every morning.
     *
     * @param array<string, mixed> $digest
     * @param array<string, int> $events
     */
    private function hasActivity(array $digest, array $events): bool
    {
        return array_sum($events) > 0
            || $digest['ar']['invoices_sent'] > 0
            || $digest['ap']['bills_received'] > 0
            || $digest['ar']['overdue'] !== []
            || $digest['sequences'] !== []
            || $digest['approvals']['waiting'] > 0;
    }

    /**
     * @return array<string, int>
     */
    private function ledgerCounts(): array
    {
        $counts = Event::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereIn('event_type', self::LEDGER_EVENTS)
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->selectRaw('event_type, COUNT(*) AS total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->all();

        return array_map(
            fn (string $type): int => (int) ($counts[$type] ?? 0),
            array_combine(self::LEDGER_EVENTS, self::LEDGER_EVENTS)
        );
    }

    private function invoicesSent(): int
    {
        return Invoice::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereBetween('sent_at', [$this->start, $this->end])
            ->count();
    }

    /**
     * Engagement stages reached today on invoices (sent / opened / declined), from tracked sends. Null when
     * they cannot be read: "unknown" must not print as zero opens.
     *
     * @return array<string, int>|null
     */
    private function invoiceEngagements(): ?array
    {
        try {
            return Engagement::query()
                ->with('stage')
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('entity_type', EngagementReferenceMap::SCRIBE_INVOICE)
                ->whereBetween('created_at', [$this->start, $this->end])
                ->get()
                ->countBy(fn (Engagement $engagement): string => (string) ($engagement->stage->slug ?? 'unknown'))
                ->all();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function invoicesBySource(): array
    {
        return Invoice::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->selectRaw('source, COUNT(*) AS total')
            ->groupBy('source')
            ->pluck('total', 'source')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @return list<array{currency: string, count: int, amount: float}>
     */
    private function openInvoices(bool $overdue): array
    {
        $query = Invoice::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('document_type', DocumentTypeEnum::INVOICE->value)
            ->open();

        if ($overdue) {
            $query->whereDate('due_date', '<', $this->day);
        } else {
            $query->whereDate('due_date', '>=', $this->day)
                ->whereDate('due_date', '<=', $this->day->copy()->addDays(self::DUE_SOON_DAYS));
        }

        return $this->byCurrency($query->get(['currency', 'balance_due_native']));
    }

    private function billsCreated(): int
    {
        return Bill::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->count();
    }

    /**
     * @return list<array{currency: string, count: int, amount: float}>
     */
    private function billsDueSoon(): array
    {
        return $this->byCurrency(Bill::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0)
            ->whereDate('due_date', '<=', $this->day->copy()->addDays(self::DUE_SOON_DAYS))
            ->get(['currency', 'balance_due_native']));
    }

    /**
     * @param Collection<int, Invoice|Bill> $documents
     * @return list<array{currency: string, count: int, amount: float}>
     */
    private function byCurrency(Collection $documents): array
    {
        return $documents
            ->groupBy(fn ($document): string => strtoupper((string) $document->currency))
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'count' => $group->count(),
                'amount' => round((float) $group->sum('balance_due_native'), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openPaymentRuns(): array
    {
        return PaymentRun::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereIn('status', [PaymentRunStatusEnum::SENT->value, PaymentRunStatusEnum::PARTIALLY_PAID->value])
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentRun $run): array => [
                'id' => $run->getId(),
                'status' => $run->status->value,
                'line_count' => $run->line_count,
                'sent_at' => $run->sent_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sequencesNeedingAttention(): array
    {
        return DocumentSequence::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where(fn ($query) => $query->whereNotNull('range_end')->orWhereNotNull('expires_at'))
            ->get()
            ->map(fn (DocumentSequence $sequence): array => [
                'document_type' => $sequence->document_type->value,
                'prefix' => $sequence->prefix,
                'remaining' => $sequence->remaining(),
                'expires_at' => $sequence->expires_at?->toDateString(),
                'reasons' => $sequence->attentionReasons($this->day),
            ])
            ->filter(fn (array $row): bool => $row['reasons'] !== [])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvals(): array
    {
        $pending = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->orderBy('created_at')
            ->get();

        $oldest = $pending->first()?->created_at;

        return [
            'waiting' => $pending->count(),
            'oldest_waiting_hours' => $oldest !== null ? (int) $oldest->diffInHours($this->day->copy()->endOfDay()) : null,
            'items' => $pending->take(10)->map(fn (ApprovalRequest $request): array => $request->emailSummary())->values()->all(),
            'approvers' => $this->approverStats(),
        ];
    }

    /**
     * Per person who decided (rule-closed steps are not a person and are left out): how many, how fast,
     * how often they said no — over the last APPROVER_STATS_DAYS.
     *
     * @return list<array<string, mixed>>
     */
    private function approverStats(): array
    {
        $rows = DB::connection('ecosystem')
            ->table('approval_request_approvers as approvers')
            ->join(
                'approval_requests as requests',
                'requests.id',
                '=',
                'approvers.approval_requests_id'
            )
            ->where('requests.apps_id', $this->app->getId())
            ->where('requests.companies_id', $this->company->getId())
            ->where('approvers.is_deleted', false)
            ->whereIn('approvers.decision', [ApprovalDecisionEnum::APPROVED->value, ApprovalDecisionEnum::REJECTED->value])
            ->whereBetween('approvers.decided_at', [$this->day->copy()->subDays(self::APPROVER_STATS_DAYS)->startOfDay(), $this->day->copy()->endOfDay()])
            ->groupBy('approvers.users_id')
            ->selectRaw('approvers.users_id AS users_id')
            ->selectRaw('COUNT(*) AS decided')
            ->selectRaw('SUM(approvers.decision = ?) AS rejected', [ApprovalDecisionEnum::REJECTED->value])
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, COALESCE(approvers.notified_at, approvers.created_at), approvers.decided_at)) AS avg_seconds')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Users::query()
            ->whereIn('id', $rows->pluck('users_id')->all())
            ->get(['id', 'firstname', 'lastname', 'email'])
            ->mapWithKeys(fn (Users $user): array => [$user->getId() => $this->userName($user)]);

        return $rows
            ->map(fn ($row): array => [
                'users_id' => (int) $row->users_id,
                'name' => $names[(int) $row->users_id] ?? ('user #' . $row->users_id),
                'decided' => (int) $row->decided,
                'avg_hours' => round(((float) $row->avg_seconds) / 3600, 1),
                'rejection_rate' => round(((int) $row->rejected) / max(1, (int) $row->decided), 2),
            ])
            ->sortByDesc('decided')
            ->values()
            ->all();
    }

    /**
     * "issue_ecf disabled since …, by …": the company's agent-invoice approval policy exists but no step of it
     * applies to an agent any more (deleted, steps removed, or every step's `when` on origin excludes the
     * agent). A step conditioned on anything else (amount, source) still gates some agent invoices, so a
     * partial switch-off is not reported as off. Since/by come from the `approvals.policy_changed` audit;
     * a change made before that audit existed falls back to the row's updated_at with nobody named.
     *
     * @return array<string, mixed>|null
     */
    private function invoiceApprovalOff(): ?array
    {
        if (! new EcfProviderResolver()->issuesEcf($this->company)) {
            return null;
        }

        $policies = ApprovalPolicy::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', self::INVOICE_APPROVAL_TYPE)
            ->orderByDesc('id')
            ->get();

        if ($policies->isEmpty()) {
            return null;
        }

        $live = $policies->first(fn (ApprovalPolicy $policy): bool => $this->gatesAgentInvoices($policy));

        if ($live !== null) {
            return null;
        }

        /** @var ApprovalPolicy $policy */
        $policy = $policies->first();
        $change = Event::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('event_type', 'approvals.policy_changed')
            ->where('source_entity_id', $policy->getId())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $by = null;
        if ($change !== null && $change->actor_type === 'User' && $change->actor_id !== null) {
            $user = Users::query()->where('id', $change->actor_id)->first();
            $by = $user !== null ? $this->userName($user) : 'user #' . $change->actor_id;
        } elseif ($change !== null) {
            $by = $change->actor_type ?? 'System';
        }

        return [
            'policy_id' => $policy->getId(),
            'how' => $policy->is_deleted ? 'policy deleted' : 'no step applies to agent invoices',
            'since' => ($change?->occurred_at ?? $policy->updated_at)?->toDateString(),
            'by' => $by,
        ];
    }

    private function gatesAgentInvoices(ApprovalPolicy $policy): bool
    {
        if ($policy->is_deleted) {
            return false;
        }

        $evaluator = new ApprovalConditionEvaluatorService();

        foreach ($policy->approvalSteps() as $step) {
            if (($step->when['field'] ?? null) !== 'origin'
                || $evaluator->matches($step->when, ['origin' => ApprovalOriginEnum::AGENT->value])) {
                return true;
            }
        }

        return false;
    }

    private function userName(Users $user): string
    {
        return $user->fullName() ?: (string) $user->email;
    }
}
