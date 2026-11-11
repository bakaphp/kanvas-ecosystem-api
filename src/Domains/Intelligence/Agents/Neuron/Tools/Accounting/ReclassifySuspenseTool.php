<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Banking\Actions\ReclassifySuspenseAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use RuntimeException;

#[AgentTool(name: 'Reclassify Suspense', category: 'accounting')]
class ReclassifySuspenseTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use TrackByInputs;

    protected string $name = 'reclassify_suspense';

    protected ?string $description = 'Moves one movement parked in Suspense to the account it really belongs to — bank fees, '
        . 'an ITBIS/ISR withholding, a transfer between our own accounts, an expense that needs no bill. Posts a '
        . 'mirror entry; the original stays. Name the account by its number or exact name (e.g. "6000" or "Bank '
        . 'Fees"). For money out that paid a bill, use settle_from_suspense instead.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bank_transaction_id',
                type: PropertyType::INTEGER,
                description: 'The parked movement, from list_suspense.',
                required: true,
            ),
            new ToolProperty(
                name: 'account',
                type: PropertyType::STRING,
                description: 'The GL account: its number ("6000") or its exact name ("Bank Fees").',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bank_transaction_id, string $account): array
    {
        $transaction = $this->resolveBankTransactionOrError($bank_transaction_id);
        if (is_array($transaction)) {
            return $transaction;
        }

        $target = $this->resolveAccount($account);
        if ($target === null) {
            return $this->notFound(
                ['success' => false, 'reclassified' => false, 'reason' => 'account_not_found'],
                "No active GL account has the number or exact name \"{$account}\". Ask which account, do not guess.",
            );
        }

        if ($target->account_sub_type === AccountSubTypeEnum::SUSPENSE) {
            return $this->invalidArgs('That is the Suspense account itself.', ['reclassified' => false]);
        }

        try {
            $entry = new ReclassifySuspenseAction(
                bankTransaction: $transaction,
                targetAccount: $target,
                user: $this->user,
            )->execute();
        } catch (RuntimeException $e) {
            return $this->invalidArgs($e->getMessage(), ['reclassified' => false], 'Nothing was reclassified.');
        }

        return $this->ok([
            'reclassified' => true,
            'bank_transaction_id' => $transaction->getId(),
            'account' => $target->account_number . ' ' . $target->name,
            'journal_entry_id' => $entry->id,
        ]);
    }

    private function resolveAccount(string $account): ?Account
    {
        $needle = Str::trimToNull($account);

        if ($needle === null) {
            return null;
        }

        $accounts = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('account_number', $needle)->orWhere('name', $needle))
            ->get();

        return $accounts->count() === 1 ? $accounts->first() : null;
    }
}
