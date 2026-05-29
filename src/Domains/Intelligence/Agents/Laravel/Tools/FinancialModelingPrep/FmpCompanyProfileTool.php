<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\FinancialModelingPrep\Client;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class FmpCompanyProfileTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return 'fmp_company_profile';
    }

    public function instructions(): string
    {
        return 'Use `fmp_company_profile` to fetch sector, industry, market cap, beta, and business description for a public company. Requires a ticker symbol — call `fmp_company_search` first if you only have the company name.';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch detailed company profile including sector, industry, market cap, beta, and business description for a given stock ticker symbol.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_company_profile called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/profile', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['error' => "No profile found for symbol '{$symbol}'."]);
        }

        $item = is_array($results[0] ?? null) ? $results[0] : $results;

        return json_encode([
            'symbol' => $item['symbol'] ?? $symbol,
            'name' => $item['companyName'] ?? '',
            'sector' => $item['sector'] ?? '',
            'industry' => $item['industry'] ?? '',
            'description' => $item['description'] ?? '',
            'mktCap' => $item['marketCap'] ?? null,
            'beta' => $item['beta'] ?? null,
            'website' => $item['website'] ?? '',
            'country' => $item['country'] ?? '',
            'exchange' => $item['exchange'] ?? '',
        ]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema
                ->string()
                ->description('Stock ticker symbol (e.g. AAPL, TSLA). Use fmp_company_search to find it.')
                ->required(),
        ];
    }
}
