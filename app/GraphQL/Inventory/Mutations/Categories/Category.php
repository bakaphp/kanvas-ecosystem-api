<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Categories;

use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;

class Category
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $request
     * @return Categories
     */
    public function create(mixed $root, array $request): Categories
    {
        $dto = CategoriesDto::fromArray($request['input']);
        $category = new CreateCategory($dto);
        return $category->execute();
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $request
     * @return Categories
     */
    public function update(mixed $root, array $request): Categories
    {
        $category = CategoriesRepository::getById($request['id']);
        $category->update($request['input']);
        return $category;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $request
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $category = CategoriesRepository::getById($request['id']);
        return $category->delete();
    }

}
