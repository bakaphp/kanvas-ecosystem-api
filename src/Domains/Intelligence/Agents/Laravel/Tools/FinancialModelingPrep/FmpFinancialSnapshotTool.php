<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Connectors\FinancialModelingPrep\Client;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'FMP Financial Snapshot')]
class FmpFinancialSnapshotTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to retrieve a year-over-year financial snapshot for a public company. Returns current and prior-year values with % change for: Revenue, EBITDA, Interest Expense, Cash, Total Debt, and Stock Price. Also returns stock performance % change for 30 days, 3 months, 6 months, 1 year, and 2 years. Requires a ticker symbol — call FMP Company Search first if you only have the company name.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch a year-over-year financial snapshot (current value, prior-year value, and % change) for Revenue, EBITDA, Interest Expense, Cash, Total Debt, and Stock Price.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_financial_snapshot called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);

            $income = $client->get('/stable/income-statement', [
                'symbol' => $symbol,
                'period' => 'annual',
                'limit' => 2,
            ]);

            $balance = $client->get('/stable/balance-sheet-statement', [
                'symbol' => $symbol,
                'period' => 'annual',
                'limit' => 2,
            ]);

            $profile = $client->get('/stable/profile', ['symbol' => $symbol]);

            $priorDate = Carbon::now()->subYear()->format('Y-m-d');
            $priorEnd = Carbon::now()->subYear()->addDays(7)->format('Y-m-d');
            $historical = $client->get('/stable/historical-price-eod/full', [
                'symbol' => $symbol,
                'from' => $priorDate,
                'to' => $priorEnd,
            ]);

            $prior2yDate = Carbon::now()->subYears(2)->format('Y-m-d');
            $prior2yEnd = Carbon::now()->subYears(2)->addDays(7)->format('Y-m-d');
            $historical2y = $client->get('/stable/historical-price-eod/full', [
                'symbol' => $symbol,
                'from' => $prior2yDate,
                'to' => $prior2yEnd,
            ]);

            $priceChange = $client->get('/stable/stock-price-change', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        $incomeNow = $income[0] ?? [];
        $incomePrev = $income[1] ?? [];
        $balanceNow = $balance[0] ?? [];
        $balancePrev = $balance[1] ?? [];

        $profileItem = is_array($profile[0] ?? null) ? $profile[0] : ($profile ?: []);
        $currentPrice = $profileItem['price'] ?? null;

        $histEntries = $historical['historical'] ?? [];
        $priorClose = ! empty($histEntries) ? ($histEntries[0]['close'] ?? null) : null;

        $hist2yEntries = $historical2y['historical'] ?? [];
        $prior2yClose = ! empty($hist2yEntries) ? ($hist2yEntries[0]['close'] ?? null) : null;

        $priceChangeItem = is_array($priceChange[0] ?? null) ? $priceChange[0] : ($priceChange ?: []);

        $currency = $incomeNow['reportedCurrency'] ?? ($profileItem['currency'] ?? 'USD');

        return json_encode([
            'symbol' => $symbol,
            'currency' => $currency,
            'metrics' => [
                'revenue' => $this->metric(
                    $incomeNow['revenue'] ?? null,
                    $incomePrev['revenue'] ?? null,
                    $incomeNow['date'] ?? null,
                ),
                'ebitda' => $this->metric(
                    $incomeNow['ebitda'] ?? null,
                    $incomePrev['ebitda'] ?? null,
                    $incomeNow['date'] ?? null,
                ),
                'interest_expense' => $this->metric(
                    $incomeNow['interestExpense'] ?? null,
                    $incomePrev['interestExpense'] ?? null,
                    $incomeNow['date'] ?? null,
                ),
                'cash' => $this->metric(
                    $balanceNow['cashAndCashEquivalents'] ?? null,
                    $balancePrev['cashAndCashEquivalents'] ?? null,
                    $balanceNow['date'] ?? null,
                ),
                'total_debt' => $this->metric(
                    $balanceNow['totalDebt'] ?? null,
                    $balancePrev['totalDebt'] ?? null,
                    $balanceNow['date'] ?? null,
                ),
                'stock_price' => $this->metric(
                    $currentPrice,
                    $priorClose,
                    Carbon::now()->format('Y-m-d'),
                ),
            ],
            'stock_performance' => [
                '30d_pct' => isset($priceChangeItem['1M']) ? round((float) $priceChangeItem['1M'], 2) : null,
                '3m_pct' => isset($priceChangeItem['3M']) ? round((float) $priceChangeItem['3M'], 2) : null,
                '6m_pct' => isset($priceChangeItem['6M']) ? round((float) $priceChangeItem['6M'], 2) : null,
                '1y_pct' => isset($priceChangeItem['1Y']) ? round((float) $priceChangeItem['1Y'], 2) : null,
                '2y_pct' => $this->changePct($currentPrice, $prior2yClose),
            ],
        ]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema
                ->string()
                ->description('Stock ticker symbol (e.g. AAPL). Use fmp_company_search to find it.')
                ->required(),
        ];
    }

    /**
     * @return array{current: mixed, previous: mixed, change_pct: float|null, as_of_date: string|null}
     */
    private function metric(mixed $current, mixed $previous, ?string $asOfDate): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $this->changePct($current, $previous),
            'as_of_date' => $asOfDate,
        ];
    }

    private function changePct(mixed $current, mixed $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 2);
    }
}
