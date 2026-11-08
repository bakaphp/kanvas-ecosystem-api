<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Contracts\ExpenseAccountSuggesterInterface;
use Kanvas\Scribe\Bills\DataTransferObject\ExpenseAccountCoding;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Setup\Enums\AccountingSetupConfigurationEnum;
use Throwable;

/**
 * Codes a bill nobody coded, most certain source first: the vendor's default or history
 * (VendorExpenseAccountResolverService), then an item whose name the bill's lines carry, then an AI
 * suggestion from the company's own chart (when the company has it on). A suggestion under
 * MIN_AI_CONFIDENCE codes nothing, and the bill waits for a person.
 */
class ExpenseAccountCodingService
{
    public const float MIN_AI_CONFIDENCE = 0.3;
    private const int MAX_ACCOUNTS_IN_PROMPT = 150;

    public function __construct(
        private readonly ?ExpenseAccountSuggesterInterface $suggester = null,
    ) {
    }

    /**
     * @param list<string> $lineDescriptions
     */
    public function code(
        AppInterface $app,
        CompanyInterface $company,
        ?Organization $vendor,
        string $vendorName,
        array $lineDescriptions,
    ): ?ExpenseAccountCoding {
        if ($vendor !== null) {
            $fromVendor = new VendorExpenseAccountResolverService()->resolveWithSource($vendor);
            if ($fromVendor !== null) {
                return $fromVendor;
            }
        }

        return $this->fromItems($app, $company, $lineDescriptions)
            ?? ($this->suggestsFor($company, $lineDescriptions) ? $this->fromSuggestion(
                $app,
                $company,
                $vendorName,
                $lineDescriptions
            ) : null);
    }

    /**
     * @param list<string> $lineDescriptions
     */
    private function fromItems(AppInterface $app, CompanyInterface $company, array $lineDescriptions): ?ExpenseAccountCoding
    {
        $text = mb_strtolower(implode(' ', $lineDescriptions));
        if (trim($text) === '') {
            return null;
        }

        $items = Item::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->whereNotNull('default_expense_account_id')
            ->get(['name', 'item_number', 'default_expense_account_id']);

        foreach ($items as $item) {
            foreach ([(string) $item->item_number, (string) $item->name] as $needle) {
                if (mb_strlen(trim($needle)) >= 3 && str_contains($text, mb_strtolower(trim($needle)))) {
                    return new ExpenseAccountCoding((int) $item->default_expense_account_id, ExpenseAccountCoding::ITEM);
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $lineDescriptions
     */
    private function fromSuggestion(
        AppInterface $app,
        CompanyInterface $company,
        string $vendorName,
        array $lineDescriptions,
    ): ?ExpenseAccountCoding {
        $accounts = Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_type', AccountTypeEnum::EXPENSE->value)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->orderBy('account_number')
            ->limit(self::MAX_ACCOUNTS_IN_PROMPT)
            ->get(['id', 'account_number', 'name']);

        if ($accounts->isEmpty()) {
            return null;
        }

        try {
            $suggestion = $this->suggester()->suggest(
                $vendorName,
                $lineDescriptions,
                $accounts->map(fn (Account $account): array => [
                    'account_number' => (string) $account->account_number,
                    'name' => (string) $account->name,
                ])->all(),
            );
        } catch (Throwable $e) {
            // No suggestion is the same as an uncertain one: the bill waits for a person.
            report($e);

            return null;
        }

        $account = $accounts->first(fn (Account $account): bool => (string) $account->account_number === (string) $suggestion['account_number']);
        if ($account === null || $suggestion['confidence'] < self::MIN_AI_CONFIDENCE) {
            return null;
        }

        return new ExpenseAccountCoding(
            accountId: (int) $account->getId(),
            source: ExpenseAccountCoding::AI,
            confidence: $suggestion['confidence'],
            reasoning: $suggestion['reasoning'],
        );
    }

    /**
     * Only for a company that turned it on (the accounting setup does), and only when the bill says what
     * was bought: a vendor name alone is a guess, not a coding.
     *
     * @param list<string> $lineDescriptions
     */
    private function suggestsFor(CompanyInterface $company, array $lineDescriptions): bool
    {
        return $lineDescriptions !== []
            && filter_var($company->get(AccountingSetupConfigurationEnum::AI_EXPENSE_CODING->value), FILTER_VALIDATE_BOOLEAN);
    }

    private function suggester(): ExpenseAccountSuggesterInterface
    {
        if ($this->suggester !== null) {
            return $this->suggester;
        }

        return app()->bound(ExpenseAccountSuggesterInterface::class)
            ? app(ExpenseAccountSuggesterInterface::class)
            : new GeminiExpenseAccountSuggesterService();
    }
}
