<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Orders\Models\OrderTypes;

class Setup
{
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    public function run(): bool
    {
        //$createSystemModule = new CreateInCurrentAppAction($this->app);
        (new CreateSystemModule($this->app))->run();

        $defaultOrderType = OrderTypes::firstOrCreate([
                        'apps_id' => $this->app->getId(),
                        'companies_id' => $this->company->getId(),
                        'name' => 'Default',
                    ], [
                        'is_default' => true,
                    ]);

        return $defaultOrderType instanceof OrderTypes;
    }
}
