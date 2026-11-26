<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Override;

class ProcessContactAssigneeUpdatedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed>|null $assignee */
        $assignee = $this->payload['contact']['assignee'] ?? null;
        $people = $this->resolvePeople();

        if ($people === null) {
            return $this->unresolved('contact_not_found');
        }

        if ($assignee !== null) {
            $people->set('respondio_assignee_id', $assignee['id'] ?? null);
            $people->set('respondio_assignee_email', $assignee['email'] ?? null);
            /** @var string $firstName */
            $firstName = $assignee['firstName'] ?? '';
            /** @var string $lastName */
            $lastName = $assignee['lastName'] ?? '';
            $people->set('respondio_assignee_name', trim($firstName . ' ' . $lastName));
        }

        return [
            'status' => 'contact_assignee_updated',
            'people_id' => $people->getId(),
            'assignee_id' => $assignee['id'] ?? null,
        ];
    }
}
