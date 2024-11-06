<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\AppInterface;
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

        return $createPeople->execute();
    }
}
