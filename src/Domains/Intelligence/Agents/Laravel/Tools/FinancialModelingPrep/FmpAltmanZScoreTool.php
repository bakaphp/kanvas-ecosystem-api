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

#[AgentTool(name: 'FMP Altman Z Score', category: 'knowledge')]
class FmpAltmanZScoreTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch the Altman Z-Score for a public company. Returns the score and zone classification: Z < 1.81 = Distress Zone (high bankruptcy risk), 1.81–2.99 = Grey Zone (uncertain), Z > 2.99 = Safe Zone (low risk). Requires a ticker symbol.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch the Altman Z-Score for a public company. Returns the score and zone (Distress / Grey / Safe).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $symbol = strtoupper((string) $request->string('symbol'));

        Log::info('[FMP] fmp_altman_z_score called', ['symbol' => $symbol]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/financial-scores', ['symbol' => $symbol]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['error' => "No financial scores found for symbol '{$symbol}'."]);
        }

        $item = is_array($results[0] ?? null) ? $results[0] : $results;
        $score = isset($item['altmanZScore']) ? (float) $item['altmanZScore'] : null;

        $zone = null;
        if ($score !== null) {
            if ($score < 1.81) {
                $zone = 'Distress';
            } elseif ($score <= 2.99) {
                $zone = 'Grey';
            } else {
                $zone = 'Safe';
            }
        }

        return json_encode([
            'symbol' => $symbol,
            'altmanZScore' => $score,
            'zone' => $zone,
            'piotroskiScore' => $item['piotroskiScore'] ?? null,
            'workingCapital' => $item['workingCapital'] ?? null,
            'totalAssets' => $item['totalAssets'] ?? null,
            'retainedEarnings' => $item['retainedEarnings'] ?? null,
            'ebit' => $item['ebit'] ?? null,
            'marketCap' => $item['marketCap'] ?? null,
            'totalLiabilities' => $item['totalLiabilities'] ?? null,
            'revenue' => $item['revenue'] ?? null,
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
