<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Actions;

use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\Models\Categories;
use Baka\Support\Str;

class UpdateCategory extends CreateCategory
{
    public function execute(): Categories
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );
        
        return Categories::updateOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'apps_id' => $this->dto->app->getId(),
            'slug' => $this->dto->slug ?? Str::slug($this->dto->name),
        ], [
            'name' => $this->dto->name,
            'users_id' => $this->dto->user->getId(),
            'parent_id' => $this->dto->parent_id ? $this->dto->parent_id : null,
            'code' => $this->dto->code,
            'position' => $this->dto->position,
            'is_published' => $this->dto->is_published,
        ]);

    }
}
