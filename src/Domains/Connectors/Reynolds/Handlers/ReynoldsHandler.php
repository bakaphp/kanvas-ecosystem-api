<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Override;

class ReynoldsHandler extends BaseIntegration
{
    /**
     * Reynolds SalesAssist Insert Sales Lead spec defines a fixed enum for
     * ProspectType. We seed these four so name-based lookup in PullLeadAction /
     * PushLeadAction can resolve them at runtime without the dealer having
     * to pre-create them in Kanvas.
     */
    private const SEED_LEAD_TYPES = ['Internet', 'Phone', 'Other', 'List'];

    /**
     * LeadObserver::closeSold()/closeNotSold() match the lead's status name
     * against substrings 'active' / 'sold' / 'not sold'. These four cover
     * those cases and the Reynolds disposition lifecycle (Active -> Sold
     * happy path, Lost-Sale -> Lost, anything else -> Closed).
     */
    private const SEED_LEAD_STATUSES = ['Active', 'Sold', 'Lost', 'Closed'];

    #[Override]
    public function setup(): bool
    {
        $required = [
            'username',
            'password',
            'endpoint',
            'dealer_number',
            'store_number',
            'area_number',
            'sender_name',
        ];

        foreach ($required as $field) {
            if (empty($this->data[$field])) {
                throw new ValidationException("Reynolds setup missing field: {$field}");
            }
        }

        // Every Reynolds setting is tenant-scoped — each company owns its own
        // RCI registration, credentials, dealer/store/area, and sender name.
        // Nothing is shared at the app level, so everything lives on $company.
        $this->company->set(ConfigurationEnum::REYNOLDS_USERNAME->value, $this->data['username']);
        $this->company->set(ConfigurationEnum::REYNOLDS_PASSWORD->value, $this->data['password']);
        $this->company->set(ConfigurationEnum::REYNOLDS_ENDPOINT->value, $this->data['endpoint']);
        $this->company->set(ConfigurationEnum::REYNOLDS_SENDER_NAME->value, $this->data['sender_name']);
        $this->company->set(ConfigurationEnum::REYNOLDS_DEV_MODE->value, $this->data['dev_mode'] ?? false);
        $this->company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, $this->data['dealer_number']);
        $this->company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, $this->data['store_number']);
        $this->company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, $this->data['area_number']);
        $this->company->set(
            ConfigurationEnum::REYNOLDS_BUSINESS_UNIT_NAME->value,
            $this->data['business_unit_name'] ?? $this->company->name
        );

        $this->seedCatalogs();

        return true;
    }

    /**
     * Seed the catalogs the connector relies on for name-based lookup. Each
     * row is idempotent on (apps_id, companies_id, name) so reinvoking
     * setup() during reconfiguration does not duplicate. LeadSource is
     * also backfilled lazily from real inbound ProviderName values in
     * PullLeadAction — this only seeds the default "Kanvas" provider used
     * by Push.
     */
    private function seedCatalogs(): void
    {
        foreach (self::SEED_LEAD_TYPES as $name) {
            LeadType::firstOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'name' => $name,
                ],
                [
                    'description' => "Reynolds ProspectType: {$name}",
                    'is_active' => 1,
                ]
            );
        }

        foreach (self::SEED_LEAD_STATUSES as $i => $name) {
            LeadStatus::firstOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'name' => $name,
                ],
                [
                    'is_default' => $i === 0 ? 1 : 0,
                ]
            );
        }

        LeadSource::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'name' => 'Kanvas',
            ],
            [
                'description' => 'Default lead source used when Kanvas pushes to Reynolds (PushLeadAction)',
                'is_active' => 1,
            ]
        );
    }
}
