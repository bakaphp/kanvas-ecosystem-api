<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Kanvas\Companies\Models\CompaniesBranches;
use Illuminate\Support\Facades\Log;
class CreateParticipantAction
{
    public function __construct(
        public Apps $app,
        public CompaniesBranches $branch,
        public Users $user,
        public array $peopleData,
        public ?EventVersion $eventVersion = null
    ) {
    }

    public function execute()
    {
        // @todo search by contact type
        $peopleData = $this->peopleData[0];
        $people = PeoplesRepository::getByEmail($peopleData['contacts'][0]['value'], $this->branch->company);
        
        if (! $people) {
            $peopleData = PeopleDto::from([
                'app' => $this->app,
                'branch' => $this->branch,
                'user' => $this->user,
                'firstname' => $peopleData['firstname'],
                'lastname' => $peopleData['lastname'] ?? null,
                'contacts' => Contact::collect($peopleData['contacts'] ?? [], DataCollection::class),
                'address' => Address::collect($peopleData['address'] ?? [], DataCollection::class),
                
            ]);
            $createPeopleAction = new CreatePeopleAction($peopleData);
            $people = $createPeopleAction->execute();
        }

        $syncParticipants = new SyncPeopleWithParticipantAction($people, $this->user);
        $participant = $syncParticipants->execute();

        if ($this->eventVersion) {
            $this->eventVersion->addParticipant($participant);
        }
    }
}
