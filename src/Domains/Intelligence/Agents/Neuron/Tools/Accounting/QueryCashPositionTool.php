<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Reports\Repositories\AccountActivityRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Cash Position', category: 'accounting')]
class QueryCashPositionTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_cash_position',
            description: 'Returns the company\'s liquid cash position — every Cash-class GL account with current '
                . 'balance + grand total. Use this when the user asks "how much cash do we have", "what\'s in the '
                . 'bank", or wants to know if there\'s enough liquidity to make a payment / hire / payroll.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'as_of',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) — defaults to today.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $as_of = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $date = $as_of !== null ? Carbon::parse($as_of) : Carbon::today();

        $cashAccounts = Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_type', AccountTypeEnum::ASSET)
            ->where('is_deleted', false)
            ->where('account_sub_type', 'like', 'cash_%')
            ->orderBy('account_number')
            ->get();

        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $app,
            company: $company,
            accountIds: $cashAccounts->pluck('id')->all(),
            asOfDate: $date,
        );

        $accounts = [];
        $total = 0.0;
        foreach ($cashAccounts as $account) {
            $balance = $balances[$account->id] ?? 0.0;
            $accounts[] = [
                'account_id' => (int) $account->id,
                'account_number' => $account->account_number,
                'name' => $account->name,
                'account_sub_type' => $account->account_sub_type?->value,
                'currency' => $account->currency,
                'balance' => $balance,
            ];
            $total += $balance;
        }

        return [
            'as_of' => $date->toDateString(),
            'accounts' => $accounts,
            'total_cash' => $total,
            'currency' => 'base',
        ];
    }
}
