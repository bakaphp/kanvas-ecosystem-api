<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mindee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Mindee\DataTransferObjects\Tag;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;

class PullVehicleFromTagAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected Tag $vehicleTag,
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

        $product = Products::fromCompany($this->company)
            ->fromApp($this->app)
            ->where('slug', $this->vehicleTag->vehicleIdentificationNumber)
            ->first();

        if ($product) {
            $product->addMultipleFilesFromUrl($formattedImagesUrl);

            return $product;
        }

        $product = new Product(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            name: $this->vehicleTag->vehicleIdentificationNumber,
            productsType: $productType,
            description: $this->vehicleTag->make . ' ' . $this->vehicleTag->model,
            sku: $this->vehicleTag->vehicleIdentificationNumber,
            slug: $this->vehicleTag->vehicleIdentificationNumber,
            categories: [],
            is_published: true,
            files: $formattedImagesUrl,
            attributes: [
                [
                    'name' => 'Plate Number',
                    'value' => $this->vehicleTag->licensePlateNumber,
                ],
                [
                    'name' => 'Make',
                    'value' => $this->vehicleTag->make,
                ],
                [
                    'name' => 'Model',
                    'value' => $this->vehicleTag->model,
                ],
                [
                    'name' => 'Color',
                    'value' => $this->vehicleTag->vehicleColor,
                ],
                [
                    'name' => 'Previous Owner',
                     'value' => [
                        'name' => $this->vehicleTag->owner,
                        'id' => $this->vehicleTag->ownerId,
                     ],
                ],
            ],
        );

        return new CreateProductAction(
            productDto: $product,
            user: $this->user
        )->execute();
    }
}
