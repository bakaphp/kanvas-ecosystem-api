<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Categories;

use Kanvas\Inventory\Categories\Actions\CreateCategory as CreateCategoryAction;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;

class Category
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return Categories
     */
    public function create(mixed $root, array $request): Categories
    {
        $request = $request['input'];

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        return (new CreateCategoryAction(
            CategoriesDto::viaRequest($request, $user, $company),
            $user
        ))->execute();
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return Categories
     */
    public function update(mixed $root, array $request): Categories
    {
        $category = CategoriesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        $category->update($request['input']);
        return $category;
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $category = CategoriesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        return $category->delete();
    }
}
