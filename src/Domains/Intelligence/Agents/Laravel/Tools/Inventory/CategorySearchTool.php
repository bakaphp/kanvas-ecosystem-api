<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Inventory\Categories\Models\Categories;
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

        $query = Categories::fromApp()
            ->fromCompany()
            ->notDeleted()
            ->orderBy('name');

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
