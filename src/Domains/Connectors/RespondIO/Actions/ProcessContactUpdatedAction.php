<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Override;

class ProcessContactUpdatedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        $firstName = $contact['firstName'] ?? null;
        $lastName = $contact['lastName'] ?? null;

        $existingPeople = $this->resolvePeople();

        if ($existingPeople === null) {
            return $this->unresolved('contact_not_found');
        }

        $updated = false;

        if ($firstName !== null && $existingPeople->firstname !== $firstName) {
            $existingPeople->firstname = $firstName;
            $updated = true;
        }

        if ($lastName !== null && $existingPeople->lastname !== $lastName) {
            $existingPeople->lastname = $lastName;
            $updated = true;
        }

        $email = $contact['email'] ?? null;
        if ($email !== null) {
            $existingPeople->set('respondio_email', $email);
        }

        if ($updated) {
            $existingPeople->saveOrFail();
        }

        return [
            'status' => 'contact_updated',
            'people_id' => $existingPeople->getId(),
            'updated' => $updated,
        ];
    }
}
