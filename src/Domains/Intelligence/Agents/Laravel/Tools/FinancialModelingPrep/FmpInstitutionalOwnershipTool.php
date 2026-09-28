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

#[AgentTool(name: 'FMP Institutional Ownership', category: 'knowledge')]
class FmpInstitutionalOwnershipTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch the top institutional shareholders for a public company by ticker symbol. Returns up to 3 holders sorted by shares held, with share count, date reported, and change.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch top institutional shareholders (up to 3) for a public company by ticker symbol.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_institutional_ownership called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/institutional-holder/' . $symbol);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results) || ! is_array($results)) {
            return json_encode(['error' => "No institutional ownership data found for symbol '{$symbol}'."]);
        }

        usort($results, fn ($a, $b) => ($b['shares'] ?? 0) <=> ($a['shares'] ?? 0));

        $topHolders = array_slice(array_map(fn ($item) => [
            'holder' => $item['holder'] ?? '',
            'shares' => $item['shares'] ?? null,
            'date_reported' => $item['dateReported'] ?? null,
            'change' => $item['change'] ?? null,
        ], $results), 0, 3);

        return json_encode([
            'symbol' => $symbol,
            'top_holders' => $topHolders,
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
