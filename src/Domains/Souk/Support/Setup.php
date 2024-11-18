<?php

declare(strict_types=1);

namespace Kanvas\Souk\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Social\Interactions\Models\Interactions;
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
        // $createSystemModule->execute(Interactions::class);
        (new CreateSystemModule($this->app))->run();

        return true;
    }
}
