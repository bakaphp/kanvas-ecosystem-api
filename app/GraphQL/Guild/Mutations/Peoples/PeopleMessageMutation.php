<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use App\GraphQL\Concerns\RecordsEntityNotes;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

class PeopleMessageMutation
{
    use RecordsEntityNotes;

    public function addToNotesChannel(mixed $root, array $request): Message
    {
        $user = auth()->user();
        $input = $request['input'];

        return $this->postNoteToEntityChannel(
            $this->peopleFor((int) $input['people_id'], $user),
            $input,
            $user
        );
    }

    /**
     * Idempotent provisioning for the people that predate the observer: the frontend can open a notes
     * panel on any person without having to post a note first to make the channel exist.
     */
    public function createNotesChannel(mixed $root, array $request): Channel
    {
        $user = auth()->user();

        return $this->resolveNotesChannel(
            $this->peopleFor((int) $request['people_id'], $user)
        );
    }

    #[Override]
    protected function noteEntityLabel(): string
    {
        return 'person';
    }

    private function peopleFor(int $id, UserInterface $user): People
    {
        return $this->getPeopleById(
            $id,
            $user,
            app(Apps::class),
            $user->getCurrentCompany()
        );
    }

    private function getPeopleById(
        int $id,
        UserInterface $user,
        AppInterface $app,
        CompanyInterface $company
    ): People {
        /** @var People $people */
        $people = $user->isAppOwner()
            ? PeoplesRepository::getById(id: $id, app: $app)
            : People::getByIdFromCompanyApp($id, $company, $app);

        return $people;
    }
}
