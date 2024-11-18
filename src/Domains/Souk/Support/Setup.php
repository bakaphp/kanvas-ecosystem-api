<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

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
        $createSystemModule = new CreateInCurrentAppAction($this->app);
        (new CreateSystemModule($this->app))->run();

        return true;
    }
}
