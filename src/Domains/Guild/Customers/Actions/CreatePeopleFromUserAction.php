<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

class CreatePeopleFromUserAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompaniesBranches $branch,
        protected readonly Users $user
    ) {
    }

    /**
     * execute.
     */
    public function execute(): People
    {
        $createPeople = new CreatePeopleAction(
            new PeopleDataInput(
                app: $this->app,
                branch: $this->branch,
                user: $this->user,
                firstname: $this->user->firstname,
                contacts: Contact::collect([
                    [
                        'value' => $this->user->email,
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ],
                    ], DataCollection::class),
                address: Address::collect([], DataCollection::class),
                lastname: $this->user->lastname,
            )
        );

        $people = $createPeople->execute();

        $allowDuplicateContacts = (bool) ($this->branch->company->get(Defaults::ALLOW_DUPLICATE_CONTACTS->getValue()) ?? false);

        /**
         *  If duplicate contacts are not allowed, associate the newly created people with the user.
         *  if we allow duplicate contact we will have issues we need to resolve @todo
         */
        if (! $allowDuplicateContacts) {
            $appUser = $this->user->getAppProfile($this->app);
            $appUser->people_id = $people->id;
            $appUser->saveOrFail();
        }

        return $people;
    }
}
