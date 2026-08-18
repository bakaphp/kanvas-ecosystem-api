<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mercury\Actions\PushCustomerToMercuryAction;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Mercury Push Customer',
    description: 'Creates the organization as a customer in Mercury and stores the id it gets back, so '
        . 'invoices can be raised against it. Outbound one-way write; run it before pushing an invoice '
        . 'for a customer Mercury has not seen.',
    integration: IntegrationsEnum::MERCURY,
)]
class PushCustomerToMercuryActivity extends KanvasActivity
{
    public $tries = 3;

    /**
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    public function execute(Organization $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! empty($entity->get(CustomFieldEnum::CUSTOMER_ID->value))) {
            return $this->skip('already_in_mercury', $entity);
        }

        // Mercury delivers invoices BY email — a customer without one is a customer who can never be billed,
        // so there is nothing to create yet. Not an error: the org may simply not be a billing customer.
        if (trim((string) $entity->email) === '') {
            return $this->skip('no_email', $entity);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MERCURY,
            integrationOperation: fn (): array => [
                'mercury_customer_id' => new PushCustomerToMercuryAction($entity)->execute(),
            ],
            additionalParams: $params,
            company: $entity->company,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(string $reason, Organization $entity): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'organization_id' => $entity->getId()];
    }
}
