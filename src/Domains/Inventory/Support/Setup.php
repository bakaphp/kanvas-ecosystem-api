<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Support;

use App\GraphQL\Inventory\Mutations\Warehouses\Warehouse;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Attributes\Actions\CreateAttributeType;
use Kanvas\Inventory\Attributes\DataTransferObject\AttributesType;
use Kanvas\Inventory\Attributes\Enums\AttributeTypeEnum;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesTypes as ModelAttributesTypes;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as Category;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Channels\Models\Channels as ModelsChannels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ModelsProductsTypes;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status;
use Kanvas\Inventory\Status\Models\Status as ModelsStatus;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses as ModelsWarehouses;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class Setup
{
    /**
     * Constructor.
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     */
    public function run(): bool
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);
        $createSystemModule->execute(Products::class);
        $createSystemModule->execute(Variants::class);
        $createSystemModule->execute(Warehouse::class);
        $createSystemModule->execute(Regions::class);
        $createSystemModule->execute(Attributes::class);
        $createSystemModule->execute(Categories::class);

        $createCategory = new CreateCategory(
            new Category(
                $this->app,
                $this->company,
                $this->user,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_PARENT_ID->getValue(),
                StateEnums::DEFAULT_POSITION->getValue(),
                (bool) StateEnums::YES->getValue(),
            ),
            $this->user
        );

        $defaultCategory = $createCategory->execute();

        $createChannel = new CreateChannel(
            new Channels(
                $this->app,
                $this->company,
                $this->user,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                (bool) StateEnums::YES->getValue(),
                (bool) StateEnums::YES->getValue()
            ),
            $this->user
        );

        $defaultChannel = $createChannel->execute();

        $createRegion = new CreateRegionAction(
            new Region(
                $this->company,
                $this->app,
                $this->user,
                Currencies::where('code', 'USD')->firstOrFail(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                null,
                StateEnums::YES->getValue(),
            ),
            $this->user
        );

        $defaultRegion = $createRegion->execute();

        $createWarehouse = new CreateWarehouseAction(
            new Warehouses(
                $this->company,
                $this->app,
                $this->user,
                $defaultRegion,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                (bool) StateEnums::YES->getValue(),
                (bool) StateEnums::YES->getValue(),
            ),
            $this->user
        );

        $defaultWarehouse = $createWarehouse->execute();

        $createDefaultProductType = new CreateProductTypeAction(
            new ProductsTypes(
                $this->company,
                $this->user,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue()
            ),
            $this->user
        );

        $defaultProductType = $createDefaultProductType->execute();

        $createDefaultStatus = new CreateStatusAction(
            new Status(
                $this->app,
                $this->company,
                $this->user,
                'Default',
                true
            ),
            $this->user
        );

        $defaultStatus = $createDefaultStatus->execute();

        $createDefaultAttributeType = new CreateAttributeType(
            new AttributesType(
                $this->company,
                $this->app,
                ucfirst(AttributeTypeEnum::INPUT->value),
                AttributeTypeEnum::INPUT->value,
                true
            ),
            $this->user
        );

        (new CreateAttributeType(
            new AttributesType(
                $this->company,
                $this->app,
                ucfirst(AttributeTypeEnum::CHECKBOX->value),
                AttributeTypeEnum::CHECKBOX->value,
                false
            ),
            $this->user
        ))->execute();

        (new CreateAttributeType(
            new AttributesType(
                $this->company,
                $this->app,
                ucfirst(AttributeTypeEnum::JSON->value),
                AttributeTypeEnum::JSON->value,
                false
            ),
            $this->user
        ))->execute();

        $defaultAttributeType = $createDefaultAttributeType->execute();

        return $defaultCategory instanceof Categories &&
            $defaultChannel instanceof ModelsChannels &&
            $defaultRegion instanceof Regions &&
            $defaultWarehouse instanceof ModelsWarehouses &&
            $defaultProductType instanceof ModelsProductsTypes &&
            $defaultStatus instanceof ModelsStatus &&
            $defaultAttributeType instanceof ModelAttributesTypes;
    }
}
