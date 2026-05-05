<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class CategorySearchTool implements Tool
{
    #[Override]
    public function description(): Stringable|string
    {
        return 'List and search product categories. Returns category name, slug, and whether it has child categories. Use this to discover the category tree or find a specific category.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = $request->string('keyword');
        $allowCrossCompany = (bool) app(Apps::class)->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $query = Categories::fromApp()
            ->notDeleted()
            ->orderBy('name');

        if (! $allowCrossCompany) {
            $query->fromCompany();
        }

        if ($keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $categories = $query->limit(30)->get();

        if ($categories->isEmpty()) {
            return $keyword !== ''
                ? "No categories found matching '{$keyword}'."
                : 'No categories found.';
        }

        $results = $categories->map(fn (Categories $category) => [
            'id' => $category->getId(),
            'name' => $category->name,
            'slug' => $category->slug,
            'has_children' => (bool) $category->has_children,
            'parent_id' => $category->parent_id,
        ]);

        return $results->toJson(JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema
                ->string()
                ->description('Optional keyword to filter categories by name. Leave empty to list all categories.'),
        ];
    }
}
