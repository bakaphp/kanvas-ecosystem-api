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

#[AgentTool(name: 'FMP Company Rating')]
class FmpCompanyRatingTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch an overall financial health score and component scores (DCF, ROE, ROA, debt-to-equity, PE, price-to-book) for a public company. Requires a ticker symbol.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch financial health scores for a company: overall rating and component scores for DCF, ROE, ROA, debt-to-equity, PE ratio, and price-to-book.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_company_rating called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/ratings-snapshot', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['error' => "No rating found for symbol '{$symbol}'."]);
        }

        $item = is_array($results[0] ?? null) ? $results[0] : $results;

        return json_encode([
            'symbol' => $item['symbol'] ?? $symbol,
            'rating' => $item['rating'] ?? '',
            'overallScore' => $item['overallScore'] ?? null,
            'discountedCashFlowScore' => $item['discountedCashFlowScore'] ?? null,
            'returnOnEquityScore' => $item['returnOnEquityScore'] ?? null,
            'returnOnAssetsScore' => $item['returnOnAssetsScore'] ?? null,
            'debtToEquityScore' => $item['debtToEquityScore'] ?? null,
            'priceToEarningsScore' => $item['priceToEarningsScore'] ?? null,
            'priceToBookScore' => $item['priceToBookScore'] ?? null,
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
