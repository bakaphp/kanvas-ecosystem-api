<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
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
    public function execute(): Categories
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return Categories::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
        ], [
            'name' => $this->dto->name,
            'users_id' => $this->dto->user->getId(),
            'parent_id' => $this->dto->parent_id,
            'code' => $this->dto->code,
            'position' => $this->dto->position,
            'is_published' => $this->dto->is_published
        ]);
    }
}
