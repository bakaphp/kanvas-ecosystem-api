<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Override;

class ProcessContactLifecycleUpdatedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        $lifecycle = $this->payload['lifecycle'] ?? null;
        $oldLifecycle = $this->payload['oldLifecycle'] ?? null;

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $people = $this->findPeopleByIdentifier($this->app, $this->company, $identifier);

        if (! $people) {
            return ['status' => 'contact_not_found', 'identifier' => $identifier];
        }

        $people->set('respondio_lifecycle', $lifecycle);
        $people->set('respondio_old_lifecycle', $oldLifecycle);

        return [
            'status' => 'contact_lifecycle_updated',
            'people_id' => $people->getId(),
            'lifecycle' => $lifecycle,
            'old_lifecycle' => $oldLifecycle,
        ];
    }
}
