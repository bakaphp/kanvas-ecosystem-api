<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Support;

use App\GraphQL\Inventory\Mutations\Warehouses\Warehouse;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as Category;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class Setup
{
    /**
     * Constructor.
     *
     * @param AppInterface $app
     * @param UserInterface $user
     * @param CompanyInterface $company
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     *
     * @return bool
     */
    public function run() : bool
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
                $this->app->getId(),
                $this->company->getId(),
                $this->user->getId(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_PARENT_ID->getValue(),
                StateEnums::DEFAULT_POSITION->getValue(),
                StateEnums::YES->getValue()
            ),
            $this->user
        );

        $defaultCategory = $createCategory->execute();

        $createChannel = new CreateChannel(
            new Channels(
                $this->app->getId(),
                $this->company->getId(),
                $this->user->getId(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::YES->getValue()
            ),
            $this->user
        );

        $defaultChannel = $createChannel->execute();

        $createRegion = new CreateRegionAction(
            new Region(
                $this->company->getId(),
                $this->app->getId(),
                $this->user->getId(),
                1,
                StateEnums::DEFAULT_NAME->getValue(),
                StateEnums::DEFAULT_NAME->getValue(),
                null,
                StateEnums::YES->getValue(),
            ),
            $this->user
        );

        $defaultRegion = $createRegion->execute();

        return true;
    }
}
