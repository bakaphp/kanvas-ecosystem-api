<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;

/**
 * Resolves a People by email within a company, creating a minimal one (name + email contact, linked to
 * the given user) when none exists. Find goes through the repository; creation lives here — keeping the
 * "repositories only find, actions create" split.
 */
class CreatePeopleByEmailAction
{
    public function __construct(
        private readonly string $email,
        private readonly UserInterface $user,
        private readonly CompanyInterface $company,
        private readonly ?string $name = null,
    ) {
    }

    public function execute(): People
    {
        $people = PeoplesRepository::getByEmail($this->email, $this->company);

        if ($people instanceof People) {
            return $people;
        }

        $people = new People();
        $people->companies_id = $this->company->getId();
        $people->name = $this->name ?? explode('@', $this->email)[0];
        $people->users_id = $this->user->getId();
        $people->saveOrFail();

        $people->contacts()->save(
            new Contact([
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'value' => $this->email,
                'weight' => 100,
            ])
        );

        return $people;
    }
}
