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

#[AgentTool(name: 'FMP Company Search', category: 'knowledge')]
class FmpCompanySearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to resolve a company name to its stock ticker symbol. Call this first whenever a company name appears in the text and you need its ticker before calling any other FMP tool.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search for a company by name to resolve its stock ticker symbol. Use this first to find the symbol before fetching profile, ratings, or financial ratios.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request->string('query');
        $limit = $request->integer('limit', 5);

        Log::info('[FMP] fmp_company_search called', ['query' => $query, 'limit' => $limit]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/search-name', [
                'query' => $query,
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['companies' => [], 'message' => "No companies found matching '{$query}'."]);
        }

        $companies = array_map(fn ($item) => [
            'symbol' => $item['symbol'] ?? '',
            'name' => $item['name'] ?? '',
            'exchange' => $item['exchangeFullName'] ?? $item['exchange'] ?? '',
            'currency' => $item['currency'] ?? '',
        ], array_slice($results, 0, $limit));

        return json_encode(['companies' => $companies]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('Company name or keyword to search for.')
                ->required(),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of results to return (default: 5).'),
        ];
    }
}
