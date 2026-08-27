<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\VariantSearchService;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Variant Search', category: 'inventory')]
class VariantSearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    public function __construct(private readonly VariantSearchService $searchService = new VariantSearchService())
    {
    }

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search product variants through the configured search engine by name, SKU, EAN, or barcode. '
            . 'Returns variant details including SKU, stock, and its parent product name. '
            . 'Use this when the user asks about a specific SKU or variant name.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = (string) $request->string('keyword');

        if ($keyword === '') {
            return 'Please provide a keyword (name or SKU) to search for variants.';
        }

        $variants = $this->searchService->search($this->app, $this->company, $keyword);

        if ($variants === []) {
            return "No variants found matching '{$keyword}'.";
        }

        return json_encode($variants, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Variant name, SKU, EAN, barcode, or related search terms.')
                ->required(),
        ];
    }
}
