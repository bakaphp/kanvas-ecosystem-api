<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Actions\UpdateCategory;
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
                'app'       => $this->app,
                'company'   => $this->company,
                'user'      => $this->user,
                'name'      => $category,
                'position'  => $key,
                'parent_id' => $parentCategories ? $parentCategories->id : null,
            ]);
            $action = new UpdateCategory($dto, $this->user);
            $category = $action->execute();
            if (! $category->parent_id) {
                DB::connection('inventory')
                    ->table('categories')
                    ->where('id', $category->id)
                    ->update([
                        'path' => $category->id,
                    ]);
            }
            $parentCategories = $category;
        }

        return $parentCategories;
    }
}
