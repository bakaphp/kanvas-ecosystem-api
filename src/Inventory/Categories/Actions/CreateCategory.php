<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Categories\Actions;

use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;

class CreateCategory
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        protected CategoriesDto $dto
    ) {
    }

    /**
     * execute
     *
     * @return Categories
     */
    public function execute(): Categories
    {
        return Categories::create([
            'apps_id' => $this->dto->apps_id,
            'companies_id' => $this->dto->companies_id,
            'parent_id' => $this->dto->parent_id,
            'name' => $this->dto->name,
            'code' => $this->dto->code,
            'position' => $this->dto->position
        ]);
    }
}
