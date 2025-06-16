<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Kanvas\Companies\Actions\AddAddressToCompanyAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessNetSuiteCompanyCustomerWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        //$regionId = $this->receiver->configuration['region_id'];
        $isCompany = ! empty($this->webhookRequest->payload['fields']['companyname']);
        $netSuiteCompanyId = $this->webhookRequest->payload['id'];

        if (! $isCompany) {
            return [
                'message' => 'Not a NetSuite Company',
            ];
        }

        $payload = $this->webhookRequest->payload;
        $syncCompanyWithNetSuite = new SyncNetSuiteCustomerWithCompanyAction($this->receiver->app, $this->receiver->company);
        $company = $syncCompanyWithNetSuite->execute($netSuiteCompanyId);

        $user = $this->receiver->app->keys()->firstOrFail()->user;

        if (isset($payload['sublists']['addressbook']['line 1'])) {
            $addAddressAction = new AddAddressToCompanyAction($company, $user, $this->receiver->app);
            $addressData = $payload['sublists']['addressbook']['line 1'];
            $addAddressAction->execute(new Address(
                address: $addressData['addrtext_initialvalue'],
                city: $addressData['city_initialvalue'],
                state: $addressData['displaystate_initialvalue'],
                zip: $addressData['zip_initialvalue'],
                country: $addressData['country_initialvalue'],
                county: $addressData['county_initialvalue'],
                address_2: $addressData['addrtext2_initialvalue'],
            ), isDefault: true);
        }

        //update or create customer own channel price list
        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');

        if ($isCompany && $mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);

            $syncNetSuiteCustomerWithCompany = new SyncNetSuiteCustomerItemsListAction(
                $this->receiver->app,
                $mainCompany,
                $company
            );
            $syncNetSuiteCustomerWithCompany->execute();

            Products::fromApp($this->receiver->app)
               ->fromCompany($mainCompany)
               ->where('is_published', 1)
               ->searchable();

            return [
                'message' => 'NetSuite Company Synced',
                'netSuiteCompanyId' => $netSuiteCompanyId,
            ];
        }

        return [
            'message' => 'Not a NetSuite Company',
        ];
    }
}
