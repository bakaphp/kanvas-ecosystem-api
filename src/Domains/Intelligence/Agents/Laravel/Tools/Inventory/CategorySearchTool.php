<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Inventory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Category Search', category: 'inventory')]
class CategorySearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'List and search product categories. Returns category name, slug, and whether it has child categories. Use this to discover the category tree or find a specific category.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $keyword = (string) $request->string('keyword');
        $allowCrossCompany = (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $query = Categories::fromApp($this->app)
            ->notDeleted()
            ->orderBy('name');

        if (! $allowCrossCompany) {
            $query->fromCompany($this->company);
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
