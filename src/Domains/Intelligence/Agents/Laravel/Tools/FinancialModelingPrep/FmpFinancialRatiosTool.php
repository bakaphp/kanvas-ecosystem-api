<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep;

use Illuminate\Contracts\JsonSchema\JsonSchema;
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

#[AgentTool(name: 'FMP Financial Ratios', category: 'knowledge')]
class FmpFinancialRatiosTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch financial ratios for a public company: current ratio, quick ratio, debt-to-equity, profit margins, cash flow per share, and interest coverage — both current (TTM) and prior-year values. Requires a ticker symbol. Use these metrics to assess liquidity and leverage risk.";
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
            $ttm = $client->get('/stable/ratios-ttm', ['symbol' => $symbol]);
            $annual = $client->get('/stable/ratios', [
                'symbol' => $symbol,
                'period' => 'annual',
                'limit' => 2,
            ]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($ttm)) {
            return json_encode(['error' => "No financial ratios found for symbol '{$symbol}'."]);
        }

        $item = is_array($ttm[0] ?? null) ? $ttm[0] : $ttm;
        $annualPrev = $annual[1] ?? [];

        return json_encode([
            'symbol' => $symbol,
            'currentRatio' => $item['currentRatioTTM'] ?? null,
            'currentRatioPriorYear' => $annualPrev['currentRatio'] ?? null,
            'quickRatio' => $item['quickRatioTTM'] ?? null,
            'quickRatioPriorYear' => $annualPrev['quickRatio'] ?? null,
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
