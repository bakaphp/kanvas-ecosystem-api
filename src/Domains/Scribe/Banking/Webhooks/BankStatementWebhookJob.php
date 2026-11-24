<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Webhooks;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementAction;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementFileAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Concerns\AuthenticatesWithSharedSecret;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * `/receiver/{uuid}` entry point for anything that can push a statement: an export script, a bank's
 * scheduled export, a future DR bank API. Two shapes, both landing on the same ingest action:
 *
 *   - a file upload (CSV or PDF) — parsed exactly like a file sent to the agent
 *   - JSON rows: {bank_account_id?, account_number?, currency?, period_start?, period_end?,
 *     opening_balance?, closing_balance?, rows: [{date, amount | debit + credit, running_balance?,
 *     description?, reference?, counterparty?}]}
 *
 * The account comes from the receiver's configuration (`bank_account_id`) when it is pinned there,
 * else from the payload, else from the statement's last 4 + currency.
 */
#[WorkflowAction(
    name: 'Bank Statement Receiver',
    description: 'Receives a bank statement for one company — a CSV or PDF upload, or JSON rows — checks that '
        . 'the balances add up and that it belongs to the right account, then lands the movements and runs the '
        . 'bank matcher on them. A statement that does not balance lands nothing. Requires the shared_secret '
        . 'configuration value, sent in the X-Webhook-Secret header.',
    integration: IntegrationsEnum::KANVAS,
    requiredParams: ['shared_secret'],
    params: [
        'shared_secret' => 'Secret the sender puts in the X-Webhook-Secret header.',
        'bank_account_id' => 'Optional. Pins every statement on this receiver to one bank account.',
    ],
)]
class BankStatementWebhookJob extends ProcessWebhookJob
{
    use AuthenticatesWithSharedSecret;

    #[Override]
    public static function capturesFiles(): bool
    {
        return true;
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return self::hasValidSharedSecret($request, $receiver);
    }

    #[Override]
    public function execute(): array
    {
        $payload = is_array($this->webhookRequest->payload) ? $this->webhookRequest->payload : [];
        $account = $this->resolveAccount($payload);

        if ($account === false) {
            return ['outcome' => 'rejected', 'reason_code' => 'account_not_found', 'reason' => 'bank_account_id is not an account of this company.'];
        }

        $file = $this->uploadedFile($payload);

        if ($file !== null) {
            return new IngestBankStatementFileAction(
                app: $this->receiver->app,
                company: $this->receiver->company,
                file: $file,
                bankAccount: $account,
                user: $this->receiver->user,
            )->execute()->toArray();
        }

        return new IngestBankStatementAction(
            app: $this->receiver->app,
            company: $this->receiver->company,
            statement: $this->statementFromRows($payload),
            source: BankStatementSourceEnum::WEBHOOK,
            bankAccount: $account,
            user: $this->receiver->user,
        )->execute()->toArray();
    }

    /**
     * Null when nothing names an account (the statement header decides); false when something names one
     * that is not this company's.
     */
    private function resolveAccount(array $payload): BankAccount|false|null
    {
        $accountId = ($this->receiver->configuration ?? [])['bank_account_id'] ?? $payload['bank_account_id'] ?? null;

        if ($accountId === null || $accountId === '') {
            return null;
        }

        return BankAccount::query()
            ->where('id', (int) $accountId)
            ->where('apps_id', $this->receiver->apps_id)
            ->where('companies_id', $this->receiver->companies_id)
            ->where('is_deleted', false)
            ->first() ?? false;
    }

    private function uploadedFile(array $payload): ?Filesystem
    {
        foreach ((array) ($payload['uploaded_files'] ?? []) as $upload) {
            if (! is_array($upload) || ! isset($upload['filesystem_id'])) {
                continue;
            }

            $file = Filesystem::query()
                ->where('id', (int) $upload['filesystem_id'])
                ->where('apps_id', $this->receiver->apps_id)
                ->where('companies_id', $this->receiver->companies_id)
                ->first();

            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    private function statementFromRows(array $payload): ParsedBankStatement
    {
        $lines = [];

        foreach ((array) ($payload['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $line = $this->line($row);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return new ParsedBankStatement(
            lines: $lines,
            accountNumber: Str::trimmedStringOrNull($payload['account_number'] ?? null),
            currency: Str::trimmedStringOrNull($payload['currency'] ?? null),
            periodStart: $this->date($payload['period_start'] ?? null),
            periodEnd: $this->date($payload['period_end'] ?? null),
            openingBalance: is_numeric($payload['opening_balance'] ?? null) ? (float) $payload['opening_balance'] : null,
            closingBalance: is_numeric($payload['closing_balance'] ?? null) ? (float) $payload['closing_balance'] : null,
        );
    }

    private function line(array $row): ?BankStatementLine
    {
        $date = $this->date($row['date'] ?? null);

        $amount = match (true) {
            is_numeric($row['amount'] ?? null) => (float) $row['amount'],
            is_numeric($row['debit'] ?? null) || is_numeric($row['credit'] ?? null) => abs((float) ($row['credit'] ?? 0)) - abs((float) ($row['debit'] ?? 0)),
            default => null,
        };

        if ($date === null || $amount === null) {
            return null;
        }

        return new BankStatementLine(
            date: $date,
            amount: $amount,
            description: Str::trimmedStringOrNull($row['description'] ?? null),
            reference: is_scalar($row['reference'] ?? null) ? Str::trimToNull((string) $row['reference']) : null,
            runningBalance: is_numeric($row['running_balance'] ?? null) ? (float) $row['running_balance'] : null,
            counterparty: Str::trimmedStringOrNull($row['counterparty'] ?? null),
            raw: $row,
        );
    }

    private function date(mixed $value): ?Carbon
    {
        $text = Str::trimmedStringOrNull($value);

        return $text === null ? null : DateHelper::parseCarbonStrict($text)?->startOfDay();
    }
}
