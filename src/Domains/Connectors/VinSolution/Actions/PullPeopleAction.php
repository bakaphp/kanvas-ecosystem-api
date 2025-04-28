<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\DataTransferObject\People;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;

class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(?string $email = null, ?string $phone = null): array
    {
        $vinCompany = Dealer::getById($this->company->get(ConfigurationEnum::COMPANY->value), $this->app);

        $vinUserId = $this->user->get(ConfigurationEnum::getUserKey($this->company, $this->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->app,
        );
        $vinContact = Contact::getAll(
            $vinCompany,
            $user,
            '',
            [
                'email' => $email,
            ]
        );

        if (! empty($vinContact)) {
            $vinContact = new Contact($vinContact[0]);
            $peopleDto = People::fromContact($vinContact, $this->app, $this->company, $this->user);

            return [
                new SyncPeopleByThirdPartyCustomFieldAction($peopleDto)->execute(),
            ];
        }

        return [];
    }
}
