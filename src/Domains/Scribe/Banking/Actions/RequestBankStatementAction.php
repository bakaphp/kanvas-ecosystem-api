<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\Setup\Actions\CreateAccountingAgentTaskAction;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Users\Models\Users;

/**
 * The agent asks for the movements it is missing. Hands the company's AP agent a one-off task: message
 * the account's owner — the user on the bank account, else the company owner, the same last resort the
 * approvals domain uses — for the movements since the last covered date, and ingest what comes back.
 *
 * Asks at most once per REQUEST_COOLDOWN_DAYS per account, so the 10th / 20th / month-end runs do not
 * stack three reminders on someone who has not answered the first. With no AP agent hired the request
 * is still recorded in the ledger, so the digest shows the gap.
 */
class RequestBankStatementAction
{
    public const string REQUESTED_AT_KEY = 'statement_requested_at';
    public const int REQUEST_COOLDOWN_DAYS = 5;

    public function __construct(
        public readonly BankAccount $bankAccount,
        protected readonly BankStatementCoverageService $coverage = new BankStatementCoverageService(),
    ) {
    }

    public function execute(): ?ScheduledAction
    {
        if ($this->recentlyRequested()) {
            return null;
        }

        $recipient = $this->recipient();
        $agent = $this->accountsPayableAgent();
        $coveredThrough = $this->coverage->coveredThrough($this->bankAccount);

        $task = $agent !== null && $recipient !== null
            ? new CreateAccountingAgentTaskAction(
                agent: $agent,
                onBehalfOf: $recipient,
                instruction: $this->instruction($recipient, $coveredThrough),
                sourceEntityType: BankAccount::class,
                sourceEntityId: $this->bankAccount->getId(),
            )->execute()
            : null;

        $metadata = $this->bankAccount->metadata ?? [];
        $metadata[self::REQUESTED_AT_KEY] = Carbon::now()->toIso8601String();
        $this->bankAccount->metadata = $metadata;
        $this->bankAccount->save();

        new AppendEventAction(
            new EventData(
                app: $this->bankAccount->app,
                company: $this->bankAccount->company,
                sourceDomain: 'Scribe',
                eventType: 'accounting.bank_statement.requested',
                sourceEntityType: BankAccount::class,
                sourceEntityId: $this->bankAccount->getId(),
                actorType: 'System',
                payload: [
                    'bank_account_id' => $this->bankAccount->getId(),
                    'covered_through' => $coveredThrough?->toDateString(),
                    'recipient_users_id' => $recipient?->getId(),
                    'agent_id' => $agent?->getId(),
                    'scheduled_action_id' => $task?->getId(),
                    'delivered_to_agent' => $task !== null,
                ],
            ),
        )->execute();

        return $task;
    }

    private function recentlyRequested(): bool
    {
        $requestedAt = $this->bankAccount->metadata[self::REQUESTED_AT_KEY] ?? null;

        return $requestedAt !== null
            && Carbon::parse($requestedAt)->gt(Carbon::now()->subDays(self::REQUEST_COOLDOWN_DAYS));
    }

    private function recipient(): ?Users
    {
        return $this->bankAccount->user ?? $this->bankAccount->company?->user;
    }

    private function accountsPayableAgent(): ?Agent
    {
        return new AccountingAgentLocatorService()->payables($this->bankAccount->app, $this->bankAccount->company);
    }

    private function instruction(Users $recipient, ?Carbon $coveredThrough): string
    {
        $account = $this->bankAccount;
        $label = trim(implode(' ', array_filter([
            $account->institution_name,
            $account->currency,
            $account->account_number_last4 !== null ? "ending {$account->account_number_last4}" : null,
        ])));
        $since = $coveredThrough === null
            ? 'there is no statement on file yet, so ask for the latest full month and everything after it'
            : 'the statements on file cover through ' . $coveredThrough->toDateString()
                . ', so ask for the movements from ' . $coveredThrough->copy()->addDay()->toDateString() . ' to today';

        return "Bank statement coverage is behind for the bank account \"{$account->account_name}\" ({$label}, "
            . "bank_account_id {$account->getId()}): {$since}. Message {$recipient->firstname} ({$recipient->email}) "
            . 'directly with send_email_to_user (or a Slack DM if that is how they reach you) and ask them to send '
            . 'the statement as a CSV export or PDF. Keep it to two sentences. When the file comes back, run '
            . "ingest_bank_statement with bank_account_id {$account->getId()} and tell them the result.";
    }
}
