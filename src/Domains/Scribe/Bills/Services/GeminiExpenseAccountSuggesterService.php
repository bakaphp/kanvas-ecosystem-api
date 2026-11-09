<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Scribe\Bills\Contracts\ExpenseAccountSuggesterInterface;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Override;

use function Laravel\Ai\agent;

/**
 * One structured Gemini call: the vendor, what it billed, and the company's own expense accounts in;
 * one of those accounts (or none) and a confidence out.
 */
class GeminiExpenseAccountSuggesterService implements ExpenseAccountSuggesterInterface
{
    public const string DEFAULT_MODEL = 'gemini-2.5-flash';

    #[Override]
    public function suggest(string $vendorName, array $lineDescriptions, array $accounts): array
    {
        $chart = implode("\n", array_map(
            fn (array $account): string => "- {$account['account_number']}: {$account['name']}",
            $accounts,
        ));
        $lines = implode("\n", array_map(fn (string $line): string => "- {$line}", $lineDescriptions));

        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema): array => [
                'account_number' => $schema->string()
                    ->description('The account_number from the list that fits best, or an empty string when none fits.')
                    ->required(),
                'confidence' => $schema->number()->description('0..1, how sure you are.')->required(),
                'reasoning' => $schema->string()->description('One sentence.'),
            ],
        )->prompt(
            "Pick the expense account a bookkeeper would code this supplier bill to.\n"
            . "Supplier: {$vendorName}\nWhat was billed:\n{$lines}\n\n"
            . "Choose only from these accounts of the company:\n{$chart}\n\n"
            . 'Answer with an empty account_number and a low confidence when the bill gives too little to decide.',
            provider: Lab::Gemini,
            model: $this->model(),
            timeout: 30,
        );

        $decoded = (array) $response->structured;

        return [
            'account_number' => is_string($decoded['account_number'] ?? null) ? trim($decoded['account_number']) : null,
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.0,
            'reasoning' => is_string($decoded['reasoning'] ?? null) ? $decoded['reasoning'] : null,
        ];
    }

    private function model(): string
    {
        $configured = app(Apps::class)->get(ConfigurationEnum::GEMINI_MODEL->value);

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_MODEL;
    }
}
