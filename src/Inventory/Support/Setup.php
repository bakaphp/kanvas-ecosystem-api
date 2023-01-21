<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Support;

use App\GraphQL\Inventory\Mutations\Warehouses\Warehouse;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\SystemModules\Models\SystemModules;

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

    public function run() : bool
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);
        $createSystemModule->execute(Products::class);
        $createSystemModule->execute(Variants::class);
        $createSystemModule->execute(Warehouse::class);
        $createSystemModule->execute(Regions::class);
        $createSystemModule->execute(Attributes::class);
        $createSystemModule->execute(Categories::class);


        return true;
    }
}
