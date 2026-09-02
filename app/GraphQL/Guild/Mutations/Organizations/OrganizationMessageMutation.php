<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use App\GraphQL\Concerns\RecordsEntityNotes;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

class OrganizationMessageMutation
{
    use RecordsEntityNotes;

    public function addToNotesChannel(mixed $root, array $request): Message
    {
        $user = auth()->user();
        $input = $request['input'];

        return $this->postNoteToEntityChannel(
            $this->organizationFor((int) $input['organization_id'], $user),
            $input,
            $user
        );
    }

    /**
     * Idempotent provisioning for the organizations that predate the observer: the frontend can open a
     * notes panel on any organization without having to post a note first to make the channel exist.
     */
    public function createNotesChannel(mixed $root, array $request): Channel
    {
        $user = auth()->user();

        return $this->resolveNotesChannel(
            $this->organizationFor((int) $request['organization_id'], $user),
            $user
        );
    }

    #[Override]
    protected function noteEntityLabel(): string
    {
        return 'organization';
    }

    private function organizationFor(int $id, UserInterface $user): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp(
            $id,
            $user->getCurrentCompany(),
            app(Apps::class)
        );

        return $organization;
    }
}
