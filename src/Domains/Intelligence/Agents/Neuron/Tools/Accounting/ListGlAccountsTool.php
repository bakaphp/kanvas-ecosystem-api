<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List GL Accounts', category: 'accounting')]
class ListGlAccountsTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use ReportsToolOutcome;

    protected string $name = 'list_gl_accounts';

    protected ?string $description = 'Lists the company\'s chart of accounts (number, name, type, sub-type, currency), '
        . 'all of it or one type (expense, cogs, asset, liability, equity, revenue, other_income, other_expense), '
        . 'optionally searched by number or name. Use it when a person names an account for a bill or expense line '
        . 'or asks which accounts exist. An uncoded create_ap_bill line is coded automatically, so never look up an '
        . 'account just to fill gl_account_number.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'type',
                type: PropertyType::STRING,
                description: 'Account type to list: expense, cogs, asset, liability, equity, revenue, other_income or '
                    . 'other_expense. Omit for every type.',
                required: false,
            ),
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Part of an account number or name, e.g. "6600" or "software".',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max accounts to return. Defaults to 100, max 300.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $type = null, ?string $search = null, ?int $limit = null): array
    {
        $type = Str::trimToNull($type);
        $search = Str::trimToNull($search);
        $limit = max(1, min(300, $limit ?? 100));
        $accountType = $type !== null ? AccountTypeEnum::tryFrom(strtolower($type)) : null;

        if ($type !== null && $accountType === null) {
            return $this->invalidArgs(
                "\"{$type}\" is not an account type.",
                ['accounts' => [], 'message' => 'Use one of: ' . implode(', ', array_column(AccountTypeEnum::cases(), 'value')) . '.'],
            );
        }

        return $this->oncePerTurn(
            ['type' => $accountType?->value, 'search' => $search, 'limit' => $limit],
            fn (): array => $this->list($accountType, $search, $limit)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function list(?AccountTypeEnum $accountType, ?string $search, int $limit): array
    {
        $accounts = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_active', true)
            ->notDeleted()
            ->when($accountType !== null, fn (Builder $query): Builder => $query->where('account_type', $accountType->value))
            ->when(
                $search !== null,
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('account_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                )
            )
            ->orderBy('account_number')
            ->limit($limit)
            ->get();

        return $this->ok([
            'count' => $accounts->count(),
            'accounts' => $accounts->map(static fn (Account $account): array => [
                'account_number' => (string) $account->account_number,
                'name' => $account->name,
                'type' => $account->account_type->value,
                'sub_type' => $account->account_sub_type?->value,
                'currency' => $account->currency,
            ])->values()->all(),
            'message' => $accounts->isEmpty()
                ? 'No active account matches. Do not guess a number; ask the person or widen the search.'
                : 'Code a line with the account_number the person meant.',
        ]);
    }
}
