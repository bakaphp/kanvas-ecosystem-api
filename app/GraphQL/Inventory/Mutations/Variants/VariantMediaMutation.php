<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantMediaMutation
{
    use HasMutationUploadFiles;

    public function attachFileToVariant(mixed $root, array $request): Products
    {
        $app = app(Apps::class);
        $variant = Variants::getById((int) $request['id'], $app);

        return $this->uploadFileToEntity(
            model: $variant,
            app: $app,
            user: auth()->user(),
            request: $request
        );
    }
}
