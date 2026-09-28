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

#[AgentTool(name: 'FMP Key Executives', category: 'knowledge')]
class FmpKeyExecutivesTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch the structured list of key executives for a public company (name, title, compensation, year born). Requires a ticker symbol — call `FMP Company Search` first if you only have the company name. Use the returned data as the base for the executive_team section in company_profile, then enrich each person with a detailed bio from your knowledge.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch the key executives of a public company including their title, compensation, and year born.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_key_executives called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/key-executives', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['executives' => [], 'message' => "No executive data found for symbol '{$symbol}'."]);
        }

        $executives = array_map(fn ($item) => [
            'name' => $item['name'] ?? '',
            'title' => $item['title'] ?? '',
            'pay' => $item['pay'] ?? null,
            'currencyPay' => $item['currencyPay'] ?? 'USD',
            'yearBorn' => $item['yearBorn'] ?? null,
            'titleSince' => $item['titleSince'] ?? null,
        ], $results);

        return json_encode(['executives' => $executives]);
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
