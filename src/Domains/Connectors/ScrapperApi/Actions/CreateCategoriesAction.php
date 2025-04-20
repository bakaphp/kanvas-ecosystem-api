<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Users\Models\Users;

class CreateCategoriesAction
{
    public function __construct(
        public Apps $app,
        public Users $user,
        public Companies $company,
        protected string $categories,
    ) {
    }

    public function execute(): Categories
    {
        $categories = explode('›', $this->categories);
        $parentCategories = null;
        foreach ($categories as $key => $category) {
            $dto = CategoriesDto::from([
                'app' => $this->app,
                'company' => $this->company,
                'user' => $this->user,
                'name' => $category,
                'position' => $key,
                'parent_id' => null,
            ]);
            $action = new CreateCategory($dto, $this->user);
            $category = $action->execute();
            if ($parentCategories) {
                $category = $category->parent()->associate($parentCategories);
                $category->save();
            } else {
                $category->parent_id = null;
                $category->save();
            }
            $parentCategories = $category;
        }

        return $parentCategories;
    }
}
