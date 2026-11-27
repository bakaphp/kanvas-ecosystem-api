<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Importers;

use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithOrganizationAction;
use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerSearchService;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

class NetSuiteCustomersImporter extends ChunkedImporter
{
    use NormalizesImportIds;

    protected NetSuiteCustomerSearchService $customerSearch;
    protected SyncNetSuiteCustomerWithOrganizationAction $syncCustomer;

    public function __construct(IntegrationsCompany $integrationCompany, Users $user)
    {
        parent::__construct($integrationCompany, $user);

        $this->customerSearch = new NetSuiteCustomerSearchService($this->app(), $this->company());
        $this->syncCustomer = new SyncNetSuiteCustomerWithOrganizationAction($this->app(), $this->company(), $user);
    }

    #[Override]
    public function prepare(array $options): array
    {
        return [
            ...$options,
            'saved_search_id' => ImportEntityEnum::CUSTOMERS->resolveSavedSearchId(
                $this->app(),
                $this->company(),
                $options['saved_search_id'] ?? null
            ),
        ];
    }

    #[Override]
    public function list(array $options): array
    {
        $savedSearchId = $options['saved_search_id'] ?? null;

        return $this->uniqueIds(
            $savedSearchId === null
                ? $this->customerSearch->activeCustomerIds()
                : array_column($this->customerSearch->searchWithSavedSearch($savedSearchId), 'internalId')
        );
    }

    /**
     * An existing customer is left as it is: the sync action returns it without calling NetSuite.
     */
    #[Override]
    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        return $this->syncCustomer->execute((string) $item)->wasRecentlyCreated
            ? ImportOutcomeEnum::CREATED
            : ImportOutcomeEnum::SKIPPED;
    }
}
