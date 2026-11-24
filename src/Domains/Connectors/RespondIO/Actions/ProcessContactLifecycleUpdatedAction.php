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
        $lifecycle = $this->payload['lifecycle'] ?? null;
        $oldLifecycle = $this->payload['oldLifecycle'] ?? null;
        $people = $this->resolvePeople();

        if ($people === null) {
            return $this->unresolved('contact_not_found');
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
