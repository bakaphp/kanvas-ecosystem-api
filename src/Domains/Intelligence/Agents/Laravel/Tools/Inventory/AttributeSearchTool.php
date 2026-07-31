<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Attribute Search', category: 'inventory')]
class AttributeSearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'List and search product attributes. Returns attribute name, type, whether it is filterable/searchable,
                and its default allowed values. Use this to discover which attributes exist and what values are valid for each one.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = (string) $request->string('keyword');
        $allowCrossCompany = (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $query = Attributes::fromApp($this->app)
            ->notDeleted()
            ->with(['attributeType', 'defaultValues'])
            ->orderBy('name');

        if (! $allowCrossCompany) {
            $query->whereIn('companies_id', [0, $this->company->getId()]);
        }

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
