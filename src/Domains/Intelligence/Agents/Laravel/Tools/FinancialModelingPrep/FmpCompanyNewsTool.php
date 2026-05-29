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

class FmpCompanyNewsTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return 'fmp_company_news';
    }

    public function instructions(): string
    {
        return 'Use `fmp_company_news` to retrieve recent market news articles. Pass the company name as `query` to filter relevant articles. Use this to gather context about recent events surrounding a company.';
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Retrieve recent FMP market news articles. Filter by company name using the query parameter. Returns titles, content summaries, and dates.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = strtolower((string) $request->string('query', ''));
        $limit = $request->integer('limit', 10);

        Log::info('[FMP] fmp_company_news called', ['query' => $query, 'limit' => $limit]);

        try {
            $client = new Client($this->app);
            $results = $client->get('/stable/fmp-articles', ['limit' => $limit * 3]);
        } catch (Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }

        if (empty($results)) {
            return json_encode(['articles' => [], 'message' => 'No articles found.']);
        }

        $articles = $results;
        if (! empty($query)) {
            $articles = array_values(array_filter(
                $results,
                fn ($item) => str_contains(strtolower($item['title'] ?? ''), $query)
                    || str_contains(strtolower($item['content'] ?? ''), $query)
            ));
        }

        $articles = array_map(fn ($item) => [
            'title' => $item['title'] ?? '',
            'summary' => isset($item['content']) ? strip_tags(substr($item['content'], 0, 400)) : '',
            'publishedDate' => $item['date'] ?? '',
        ], array_slice($articles, 0, $limit));

        return json_encode(['articles' => $articles]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('Company name keyword to filter articles (e.g. "Apple"). Leave empty for general market news.'),
            'limit' => $schema
                ->integer()
                ->description('Number of articles to return (default: 10).'),
        ];
    }
}
