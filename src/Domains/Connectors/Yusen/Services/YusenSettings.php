<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Yusen\Enums\ConfigurationEnum;

/**
 * Every Yusen setting resolves company-first, app-second: one app can serve several tenants,
 * each comparing against its own warehouse, while the saved-search id and match field are usually
 * set once at the app level.
 */
class YusenSettings
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function matchField(): string
    {
        $field = (string) ($this->raw(ConfigurationEnum::MATCH_FIELD) ?? 'barcode');

        return in_array($field, ['barcode', 'sku'], true) ? $field : 'barcode';
    }

    public function primaryWarehouseId(): ?int
    {
        $value = $this->raw(ConfigurationEnum::PRIMARY_WAREHOUSE_ID);

        return $value !== null ? (int) $value : null;
    }

    public function netSuiteSavedSearchId(): string
    {
        return (string) ($this->raw(ConfigurationEnum::NETSUITE_SAVED_SEARCH_ID) ?? '576');
    }

    public function quantityTolerance(): float
    {
        return (float) ($this->raw(ConfigurationEnum::QUANTITY_TOLERANCE) ?? 0);
    }

    public function reconcileWithNetSuite(): bool
    {
        return (bool) ($this->raw(ConfigurationEnum::RECONCILE_WITH_NETSUITE) ?? true);
    }

    /**
     * User ids that receive the emailed report. Empty means nobody asked for it, and the run
     * stays silent rather than guessing at a recipient.
     *
     * @return array<int, int>
     */
    public function reportUsers(): array
    {
        $users = $this->raw(ConfigurationEnum::REPORT_USERS);

        if (is_string($users)) {
            $users = json_decode($users, true);
        }

        return is_array($users) ? array_values(array_map('intval', $users)) : [];
    }

    private function raw(ConfigurationEnum $key): mixed
    {
        return $this->company->get($key->value) ?? $this->app->get($key->value);
    }
}
