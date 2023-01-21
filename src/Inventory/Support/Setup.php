<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;

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
}
