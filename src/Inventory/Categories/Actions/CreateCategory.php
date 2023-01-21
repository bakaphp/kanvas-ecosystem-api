<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;

class CreateCategory
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected CategoriesDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return Categories
     */
    public function execute() : Categories
    {
        CompaniesRepository::userAssociatedToCompany(
            Companies::getById($this->dto->companies_id),
            $this->user
        );

        return Categories::firstOrCreate([
            'companies_id' => $this->dto->companies_id,
            'apps_id' => $this->dto->apps_id,
            'name' => $this->dto->name,
        ], [
            'users_id' => $this->dto->users_id,
            'parent_id' => $this->dto->parent_id,
            'code' => $this->dto->code,
            'position' => $this->dto->position,
            'is_published' => $this->dto->is_published
        ]);
    }
}
