<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Inventory\Products\Models\Products;

class ProductMediaMutation
{
    use HasMutationUploadFiles;

    public function attachFileToProduct(mixed $root, array $request): Products
    {
        $app = app(Apps::class);
        $product = Products::getById((int) $request['id']);

        return $this->uploadFileToEntity(
            model: $product,
            app: $app,
            user: auth()->user(),
            request: $request
        );
    }
}
