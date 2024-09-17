<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\DataTransferObject;

use Baka\Contracts\AppInterface;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Spatie\LaravelData\Data;

class EntityIntegrationHistory extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public AppInterface $app,
        public IntegrationsCompany $integrationCompany,
        public Status $status,
        public EntityIntegrationInterface $entity,
        public ?string $response = null,
        public mixed $exception = null
    ) {
    }
}
