<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Connectors\FinancialModelingPrep;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\FinancialModelingPrep\Client;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class FmpFinancialRatiosTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return 'fmp_financial_ratios';
    }

    public function instructions(): string
    {
        return 'Use `fmp_financial_ratios` to fetch TTM financial ratios (current ratio, debt-to-equity, profit margins, cash flow per share) for a public company. Requires a ticker symbol. Use these metrics to assess liquidity and leverage risk.';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch trailing-twelve-month (TTM) financial ratios for a company: liquidity (current ratio), leverage (debt-to-equity), cash flow per share, and profitability margins.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_financial_ratios called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/ratios-ttm', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['error' => "No financial ratios found for symbol '{$symbol}'."]);
        }

        $item = is_array($results[0] ?? null) ? $results[0] : $results;

        return json_encode([
            'symbol' => $symbol,
            'currentRatio' => $item['currentRatioTTM'] ?? null,
            'debtToEquityRatio' => $item['debtToEquityRatioTTM'] ?? null,
            'debtToAssetsRatio' => $item['debtToAssetsRatioTTM'] ?? null,
            'operatingCashFlowPerShare' => $item['operatingCashFlowPerShareTTM'] ?? null,
            'freeCashFlowPerShare' => $item['freeCashFlowPerShareTTM'] ?? null,
            'netProfitMargin' => $item['netProfitMarginTTM'] ?? null,
            'operatingProfitMargin' => $item['operatingProfitMarginTTM'] ?? null,
            'interestCoverage' => $item['interestCoverageRatioTTM'] ?? null,
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
}
