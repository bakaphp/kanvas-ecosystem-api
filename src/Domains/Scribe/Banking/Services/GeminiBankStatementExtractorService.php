<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Scribe\Banking\Contracts\BankStatementExtractorInterface;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\PdfIngest\Exceptions\UnsupportedDocumentTypeException;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Override;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent;

/**
 * One structured Gemini call per statement, the same transport the accounting inbox classifier uses.
 * The model returns header balances plus one row per movement; debits and credits come back as separate
 * unsigned columns because a model is far more reliable copying two columns than inferring a sign.
 */
class GeminiBankStatementExtractorService implements BankStatementExtractorInterface
{
    public function __construct(
        protected readonly ?string $model = null,
        protected readonly int $timeoutSeconds = 120,
    ) {
    }

    #[Override]
    public function extract(string $bytes): ParsedBankStatement
    {
        $mimeType = FilesystemServices::detectMimeTypeFromBytes($bytes);

        if (! in_array($mimeType, GeminiPdfClassifierService::SUPPORTED_MIME_TYPES, true)) {
            throw new UnsupportedDocumentTypeException($mimeType);
        }

        try {
            /** @var StructuredAgentResponse $response */
            $response = agent(
                schema: fn ($s) => [
                    'account_number' => $s->string()->description('Account number as printed (masked digits are fine). Empty when not shown.'),
                    'currency' => $s->string()->description('ISO 4217 currency of the account (DOP, USD, EUR). RD$ is DOP.'),
                    'period_start' => $s->string()->description('Statement period start, YYYY-MM-DD. Empty when not shown.'),
                    'period_end' => $s->string()->description('Statement period end, YYYY-MM-DD. Empty when not shown.'),
                    'opening_balance' => $s->number()->description('Opening / previous balance ("balance anterior", "balance inicial").'),
                    'closing_balance' => $s->number()->description('Closing / current balance ("balance final", "balance actual").'),
                    'rows' => $s->array()
                        ->description('Every movement, in the order printed. Skip subtotal and summary lines.')
                        ->items($s->object([
                            'date' => $s->string()->description('YYYY-MM-DD.')->required(),
                            'description' => $s->string()->description('Description as printed.'),
                            'reference' => $s->string()->description('Bank reference / document number. Empty when not shown.'),
                            'debit' => $s->number()->description('Money out, unsigned. 0 when the row is a credit.'),
                            'credit' => $s->number()->description('Money in, unsigned. 0 when the row is a debit.'),
                            'running_balance' => $s->number()->description('The balance column after this row, when printed.'),
                        ])),
                ],
            )->prompt(
                self::PROMPT,
                attachments: [Document::fromBase64(base64_encode($bytes), $mimeType)],
                provider: Lab::Gemini,
                model: $this->model ?? GeminiPdfClassifierService::DEFAULT_MODEL,
                timeout: $this->timeoutSeconds,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Gemini bank statement extraction failed: ' . $e->getMessage(), previous: $e);
        }

        return $this->toStatement((array) $response->structured);
    }

    private const string PROMPT = <<<'PROMPT'
You are extracting a bank account statement (Dominican Republic or US bank) for bookkeeping.
Copy values exactly as printed. Never invent a row, a balance or a reference: when a field is not on the
document, return an empty string or 0. Amounts are plain numbers without currency symbols or thousands
separators. Return every movement row, including bank fees, taxes (ITBIS, 0.15% DGII) and interest.
PROMPT;

    /**
     * @param array<string, mixed> $structured
     */
    private function toStatement(array $structured): ParsedBankStatement
    {
        $lines = [];

        foreach ((array) ($structured['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->date($row['date'] ?? null);
            $debit = abs((float) ($row['debit'] ?? 0));
            $credit = abs((float) ($row['credit'] ?? 0));

            if ($date === null || ($debit === 0.0 && $credit === 0.0)) {
                continue;
            }

            $running = $row['running_balance'] ?? null;

            $lines[] = new BankStatementLine(
                date: $date,
                amount: $credit - $debit,
                description: Str::trimmedStringOrNull($row['description'] ?? null),
                reference: Str::trimmedStringOrNull($row['reference'] ?? null),
                runningBalance: is_numeric($running) && (float) $running !== 0.0 ? (float) $running : null,
                raw: $row,
            );
        }

        return new ParsedBankStatement(
            lines: $lines,
            accountNumber: Str::trimmedStringOrNull($structured['account_number'] ?? null),
            currency: Str::trimmedStringOrNull($structured['currency'] ?? null),
            periodStart: $this->date($structured['period_start'] ?? null),
            periodEnd: $this->date($structured['period_end'] ?? null),
            openingBalance: is_numeric($structured['opening_balance'] ?? null) ? (float) $structured['opening_balance'] : null,
            closingBalance: is_numeric($structured['closing_balance'] ?? null) ? (float) $structured['closing_balance'] : null,
        );
    }

    private function date(mixed $value): ?Carbon
    {
        $text = Str::trimmedStringOrNull($value);
        $date = $text === null ? null : DateHelper::parseYmd($text);

        return $date === null ? null : Carbon::instance($date);
    }
}
