<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Categories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Inventory\Categories\Models\Categories;

class CategoryMediaMutation
{
    use HasMutationUploadFiles;

    public function attachFileToCategory(mixed $root, array $request): Categories
    {
        $app = app(Apps::class);
        $category = Categories::getById((int) $request['id'], $app);

        return $this->uploadFileToEntity(
            model: $category,
            app: $app,
            user: auth()->user(),
            request: $request
        );
    }
}
