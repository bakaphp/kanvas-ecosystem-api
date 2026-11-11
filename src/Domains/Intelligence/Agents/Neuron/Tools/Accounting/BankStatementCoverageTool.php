<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use NeuronAI\Tools\Tool;
use Override;

#[AgentTool(name: 'Bank Statement Coverage', category: 'accounting')]
class BankStatementCoverageTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'bank_statement_coverage';

    protected ?string $description = 'Lists the company\'s bank accounts with how far their movements are known: the date '
        . 'statements cover through, how many days behind that is, and the date to ask movements from. Use it '
        . 'before asking someone for a statement, to get a bank_account_id for ingest_bank_statement, and to '
        . 'report "Popular DOP: covered to Nov 15". Accounts fed by a bank API are listed but never need a statement.';

    /**
     * @return array<int, mixed>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $coverage = new BankStatementCoverageService();
        $today = Carbon::today();

        $accounts = $coverage->activeAccounts($this->app, $this->company)
            ->map(fn (BankAccount $account): array => $coverage->describe($account, $today))
            ->values()
            ->all();

        return [
            'as_of' => $today->toDateString(),
            'count' => count($accounts),
            'accounts' => $accounts,
            'note' => $accounts === []
                ? 'This company has no active bank accounts on file. Do not call again; ask for the account to be set up.'
                : null,
        ];
    }
}
