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

#[AgentTool(name: 'FMP Company Profile')]
class FmpCompanyProfileTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch the full company profile (sector, industry, market cap, beta, CEO, employees, address, exchange, and more) as a `company_profile` JSON object. Requires a ticker symbol — call `FMP Company Search` first if you only have the company name. Save the returned `company_profile` object directly to the organization custom fields.";
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
            'company_profile' => [
                'symbol' => $item['symbol'] ?? $symbol,
                'companyName' => $item['companyName'] ?? '',
                'sector' => $item['sector'] ?? '',
                'industry' => $item['industry'] ?? '',
                'description' => $item['description'] ?? '',
                'ceo' => $item['ceo'] ?? '',
                'price' => $item['price'] ?? null,
                'change' => $item['change'] ?? null,
                'changePercentage' => $item['changePercentage'] ?? null,
                'marketCap' => $item['marketCap'] ?? null,
                'beta' => $item['beta'] ?? null,
                'lastDividend' => $item['lastDividend'] ?? null,
                'range' => $item['range'] ?? '',
                'volume' => $item['volume'] ?? null,
                'averageVolume' => $item['averageVolume'] ?? null,
                'currency' => $item['currency'] ?? '',
                'exchange' => $item['exchange'] ?? '',
                'exchangeFullName' => $item['exchangeFullName'] ?? '',
                'website' => $item['website'] ?? '',
                'country' => $item['country'] ?? '',
                'fullTimeEmployees' => $item['fullTimeEmployees'] ?? null,
                'phone' => $item['phone'] ?? '',
                'address' => $item['address'] ?? '',
                'city' => $item['city'] ?? '',
                'state' => $item['state'] ?? '',
                'zip' => $item['zip'] ?? '',
                'ipoDate' => $item['ipoDate'] ?? '',
                'image' => $item['image'] ?? '',
                'isEtf' => $item['isEtf'] ?? false,
                'isActivelyTrading' => $item['isActivelyTrading'] ?? false,
                'isAdr' => $item['isAdr'] ?? false,
                'isFund' => $item['isFund'] ?? false,
                'isin' => $item['isin'] ?? '',
                'cik' => $item['cik'] ?? '',
            ],
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
