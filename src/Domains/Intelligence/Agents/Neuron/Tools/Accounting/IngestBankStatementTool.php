<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementFileAction;
use Kanvas\Scribe\Banking\Actions\SaveBankStatementMappingAction;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Services\BankStatementCsvParserService;
use Kanvas\Scribe\Banking\Services\BankStatementFileReaderService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Ingest Bank Statement', category: 'accounting')]
class IngestBankStatementTool extends Tool
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use ResolvesFilesystemForTool;
    use TrackByInputs;

    protected string $name = 'ingest_bank_statement';

    protected ?string $description = 'Lands a bank statement someone sent you (CSV export or PDF) on the books: checks the '
        . 'balances add up and that it is for the right account, records every movement once (overlapping '
        . 'statements never double a movement) and runs the bank matcher, which settles the bills and invoices it '
        . 'can identify and parks the rest in Suspense. Never read or retype the rows yourself — hand the file to '
        . 'this tool. A rejected statement landed NOTHING: relay the reason and ask for a clean file. An '
        . 'unknown_layout answer returns the CSV header: propose a mapping from it, confirm it with the person, '
        . 'and call again with the same file and `mapping`.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The file from an `[Attached file...]` marker or download_attachment. Pass this or file_url.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A link to the statement file, only when you have no filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'bank_account_id',
                type: PropertyType::INTEGER,
                description: 'Which bank account the statement is for (from bank_statement_coverage). Required for a '
                    . 'CSV when the company has more than one account; a PDF is matched by its account number.',
                required: false,
            ),
            new ToolProperty(
                name: 'mapping',
                type: PropertyType::STRING,
                description: 'Only after an unknown_layout answer: a JSON object mapping statement fields to the file\'s '
                    . 'column names. Keys: date (required), amount OR debit + credit (required), direction, '
                    . 'running_balance, description, reference, counterparty, date_format (a PHP date format '
                    . 'prefixed with "_", e.g. "_d/m/Y"), decimal_separator ("_." or "_,"). A direction column is '
                    . 'written as {"$map": "Column", "values": {"DB": "debit", "CR": "credit"}}. Needs bank_account_id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $filesystem_id = null,
        ?string $file_url = null,
        ?int $bank_account_id = null,
        ?string $mapping = null,
    ): array {
        $file = $this->resolveOrUploadTenantFile($filesystem_id, $file_url);
        if (is_array($file)) {
            return $this->invalidArgs($file['message'], ['ingested' => false, 'reason' => $file['reason']]);
        }

        $account = null;
        if ($bank_account_id !== null) {
            $account = $this->resolveBankAccountOrError($bank_account_id);
            if (is_array($account)) {
                return $account;
            }
        }

        $proposedMapping = $this->decodeJsonObjectParam($mapping);

        if ($proposedMapping !== []) {
            if ($account === null) {
                return $this->invalidArgs('A mapping is saved per bank account, so pass bank_account_id with it.', ['ingested' => false]);
            }

            try {
                $peek = new BankStatementCsvParserService()->peek(app(BankStatementFileReaderService::class)->read($file));
                new SaveBankStatementMappingAction(
                    $account,
                    $peek['header'],
                    $proposedMapping,
                    $this->user
                )->execute();
            } catch (ValidationException $e) {
                return $this->invalidArgs($e->getMessage(), ['ingested' => false, 'reason' => 'invalid_mapping']);
            }
        }

        try {
            $result = new IngestBankStatementFileAction(
                app: $this->app,
                company: $this->company,
                file: $file,
                bankAccount: $account,
                user: $this->user,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The statement could not be read: ' . $e->getMessage(), ['ingested' => false]);
        }

        $payload = ['ingested' => $result->outcome === BankStatementIngestOutcomeEnum::POSTED, ...$result->toArray()];

        return match ($result->outcome) {
            BankStatementIngestOutcomeEnum::POSTED => $this->ok(
                $payload,
                'Report the period covered, the closing balance, how many movements were new and what the matcher '
                . 'settled. Anything parked in Suspense is on list_suspense.',
            ),
            BankStatementIngestOutcomeEnum::DUPLICATE => $this->noop(
                $payload,
                'This exact statement was already on the books; nothing new landed.',
            ),
            BankStatementIngestOutcomeEnum::REJECTED => $this->denied(
                (string) $result->reason,
                $payload,
                'The statement was NOT posted and no movement landed. Tell the person why and ask for a corrected file.',
            ),
            BankStatementIngestOutcomeEnum::UNKNOWN_LAYOUT => $this->invalidArgs(
                'No mapping knows this CSV layout yet.',
                $payload,
                'Nothing was posted. Propose a mapping from the header, confirm it with the person, then call again with mapping and bank_account_id.',
            ),
        };
    }
}
