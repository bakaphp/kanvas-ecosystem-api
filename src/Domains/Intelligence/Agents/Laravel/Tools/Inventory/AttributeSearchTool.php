<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class AttributeSearchTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'List and search product attributes. Returns attribute name, type, whether it is filterable/searchable, and its default allowed values. Use this to discover which attributes exist and what values are valid for each one.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = $request->string('keyword');
        $app = app(Apps::class);
        $companyId = auth()->user()->getCurrentCompany()->getId();

        $query = Attributes::fromApp()
            ->notDeleted()
            ->whereIn('companies_id', [0, $companyId])
            ->with(['attributeType', 'defaultValues'])
            ->orderBy('name');

        if ($keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $attributes = $query->limit(20)->get();

        if ($attributes->isEmpty()) {
            return $keyword !== ''
                ? "No attributes found matching '{$keyword}'."
                : 'No attributes found for this company.';
        }

        $results = $attributes->map(fn (Attributes $attribute) => [
            'id' => $attribute->getId(),
            'name' => $attribute->name,
            'slug' => $attribute->slug,
            'type' => $attribute->attributeType?->name,
            'is_filterable' => (bool) $attribute->is_filterable,
            'is_searchable' => (bool) $attribute->is_searchable,
            'allowed_values' => $attribute->defaultValues
                ->pluck('value')
                ->filter()
                ->values()
                ->toArray(),
        ]);

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Optional keyword to filter attributes by name. Leave empty to list all attributes.'),
        ];
    }
}
