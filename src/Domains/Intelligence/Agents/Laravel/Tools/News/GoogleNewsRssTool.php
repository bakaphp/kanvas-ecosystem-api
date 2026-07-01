<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\News;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrlFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use SimpleXMLElement;
use Stringable;
use Throwable;

#[AgentTool(name: 'Google News RSS')]
class GoogleNewsRssTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function name(): string
    {
        return Str::slug(AgentTool::fromClass($this)?->name ?? class_basename($this), '_');
    }

    public function instructions(): string
    {
        $name = AgentTool::fromClass($this)?->name ?? $this->name();

        return "Use `{$name}` to fetch recent news articles for a company from Google News. Pass the company name and an optional limit (default 5, max 20). Returns an array of articles with title, URL, source, and publication date.";
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Fetch the latest news articles for a company from Google News RSS feed.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $companyName = (string) $request->string('company_name');
        $limit = max(1, min(20, (int) ($request['limit'] ?? 5)));

        $url = 'https://news.google.com/rss/search?q=' . urlencode($companyName) . '&hl=en-US&gl=US&ceid=US:en';

        try {
            $xml = SafeUrlFetcher::fetch($url);
        } catch (SsrfException $e) {
            return json_encode(['error' => 'SSRF protection blocked the request: ' . $e->getMessage()]);
        } catch (Throwable $e) {
            return json_encode(['error' => 'Failed to fetch Google News RSS: ' . $e->getMessage()]);
        }

        try {
            $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        } catch (Throwable $e) {
            return json_encode(['error' => 'Failed to parse RSS feed: ' . $e->getMessage()]);
        }

        if ($feed === false || ! isset($feed->channel->item)) {
            return json_encode(['articles' => []]);
        }

        $articles = [];
        $count = 0;

        foreach ($feed->channel->item as $item) {
            if ($count >= $limit) {
                break;
            }

            $articles[] = [
                'title' => (string) $item->title,
                'url' => (string) $item->link,
                'source' => (string) $item->source,
                'published_at' => (string) $item->pubDate,
            ];

            $count++;
        }

        return json_encode(['articles' => $articles]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'company_name' => $schema
                ->string()
                ->description('Company name to search for in Google News (e.g. "Saks Global").')
                ->required(),
            'limit' => $schema
                ->integer()
                ->description('Maximum number of articles to return. Default is 5, max is 20.'),
        ];
    }
}
