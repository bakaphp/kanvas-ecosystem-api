<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;

class ClientCredential
{
    public function __construct(
        public Dealer $dealer,
        public User $user
    ) {
    }

    public static function get(Companies $company, UserInterface $user, AppInterface $app): self
    {
        $dealer = Dealer::getById((int) $company->get(CustomFieldEnum::COMPANY->value), $app);

        return new self(
            $dealer,
            Dealer::getUser($dealer, (int) $user->get(CustomFieldEnum::getUserKey($company, $user)), $app)
        );
    }
}
