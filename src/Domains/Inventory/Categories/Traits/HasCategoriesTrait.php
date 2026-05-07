<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Models\CategoryEntity;

trait HasCategoriesTrait
{
    public function categories(): MorphToMany
    {
        $dbConnection = config('database.connections.inventory.database');

        $query = $this->morphToMany(
            Categories::class,
            'taggable',
            $dbConnection . '.categories_entities',
            'entity_id',
            'categories_id'
        )
        ->using(CategoryEntity::class);

        return $query;
    }

    public function hasCategory(array $categories): bool
    {
        if (empty($categories)) {
            return false;
        }

        return $this->categories()
            ->whereIn('name', $categories)
            ->exists();
    }

    public function addCategory(
        string|int $category,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        if (empty($category)) {
            return;
        }

        $app = $app ?? $this->app;
        $user = $user ?? $this->user;
        $company = $company ?? $this->company;

        // If category is a string (name), create or find it
        if (is_string($category)) {
            $categoryModel = Categories::fromApp($app)
                ->fromCompany($company)
                ->where('name', $category)
                ->first();

            if (! $categoryModel) {
                $categoryDto = new CategoriesDto(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: $category
                );

                $categoryModel = (new CreateCategory($categoryDto, $user))->execute();
            }
        } else {
            // If category is an ID, find it
            $categoryModel = Categories::fromApp($app)->fromCompany($company)->findOrFail($category);
        }

        // Check if the category is already attached before syncing
        if (! $this->categories()->wherePivot('categories_id', $categoryModel->getId())->exists()) {
            $this->categories()->attach($this->getId(), [
                'categories_id' => $categoryModel->getId(),
                'users_id' => $user->getId(),
                'companies_id' => $company->getId(),
                'apps_id' => $app->getId(),
                'is_deleted' => 0,
            ]);
        }
    }

    public function addCategories(
        array $categories,
        ?AppInterface $app = null,
        ?UserInterface $user = null,
        ?CompanyInterface $company = null
    ): void {
        foreach ($categories as $category) {
            if (empty($category)) {
                continue;
            }

            $this->addCategory(
                $category['name'] ?? $category,
                $app,
                $user,
                $company
            );
        }
    }

    public function removeCategory(string|int $category): void
    {
        $app = $this->app ?? app(\Kanvas\Apps\Models\Apps::class);
        $company = $this->company ?? app(\Kanvas\Companies\Models\Companies::class);

        if (is_string($category)) {
            $categoryModel = Categories::fromApp($app)
                ->fromCompany($company)
                ->where('name', $category)
                ->first();

            if (! $categoryModel) {
                return;
            }

            $categoryId = $categoryModel->getId();
        } else {
            $categoryId = $category;
        }

        CategoryEntity::where('entity_id', $this->getId())
            ->where('categories_id', $categoryId)
            ->where('taggable_type', static::class)
            ->delete();
    }

    public function removeCategories(array $categories): void
    {
        if (empty($categories)) {
            return;
        }

        foreach ($categories as $category) {
            $this->removeCategory($category);
        }
    }

    public function syncCategories(array $categories): void
    {
        $this->categories()->detach();
        $this->addCategories($categories);
    }
}
