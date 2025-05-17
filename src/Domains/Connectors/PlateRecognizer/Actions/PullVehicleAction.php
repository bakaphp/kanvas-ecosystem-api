<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\PlateRecognizer\DataTransferObject\Vehicle;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;

class PullVehicleAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected Vehicle $vehicle,
    ) {
    }

    public function execute(array $imagesUrl = []): Products
    {
        $productType = new CreateProductTypeAction(
            data: new ProductsTypes(
                company: $this->company,
                user: $this->user,
                name: 'Vehicle',
            ),
            user: $this->user
        )->execute();

        $formattedImagesUrl = [];
        foreach ($imagesUrl as $key => $imageUrl) {
            $formattedImagesUrl[] = [
                'url' => $imageUrl,
                'name' => 'image-' . $key,
            ];
        }

        $product = new Product(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: $this->vehicle->plateNumber,
            productsType: $productType,
            description: $this->vehicle->make . ' ' . $this->vehicle->model,
            sku: $this->vehicle->plateNumber,
            slug: $this->vehicle->plateNumber,
            categories: [],
            is_published: true,
            files: $formattedImagesUrl,
            attributes: [
                [
                    'name' => 'Plate Number',
                    'value' => $this->vehicle->plateNumber,
                ],
                [
                    'name' => 'Make',
                    'value' => $this->vehicle->make,
                ],
                [
                    'name' => 'Model',
                    'value' => $this->vehicle->model,
                ],
                [
                    'name' => 'Color',
                    'value' => $this->vehicle->color,
                ],
            ],
        );

        $product = Products::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('slug', $this->vehicle->plateNumber)
            ->first();

        if ($product) {
            $product->addMultipleFilesFromUrl($formattedImagesUrl);

            return $product;
        }

        return new CreateProductAction(
            productDto: $product,
            user: $this->user
        )->execute();
    }
}
