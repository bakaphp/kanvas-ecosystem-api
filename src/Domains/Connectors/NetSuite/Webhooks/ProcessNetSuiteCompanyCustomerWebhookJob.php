<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\AddAddressToCompanyAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use SoapFault;

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

        try {
            $syncCompanyWithNetSuite = new SyncNetSuiteCustomerWithCompanyAction($this->receiver->app, $this->receiver->company);
            $company = $syncCompanyWithNetSuite->execute($netSuiteCompanyId);

            $user = $this->receiver->app->keys()->firstOrFail()->user;

            if (isset($payload['sublists']['addressbook']['line 1'])) {
                $addAddressAction = new AddAddressToCompanyAction($company, $user, $this->receiver->app);
                $addressData = $payload['sublists']['addressbook']['line 1'];
                $addAddressAction->fromNetSuite($addressData);
            }

            $this->setCompanyDocuments($company, $this->receiver->app);

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
        } catch (SoapFault $e) {
            // Check if it's a NetSuite rate limit error
            if ($this->isNetSuiteRateLimitError($e)) {
                // Return success response with rate limit message
                // This prevents the webhook from being retried immediately
                return [
                    'message' => 'NetSuite sync delayed due to rate limiting',
                    'status' => 'rate_limited',
                    'netSuiteCompanyId' => $netSuiteCompanyId,
                    'retry_suggested' => true,
                ];
            }

            //throw $e; // Re-throw non-rate-limit errors
            return [
                'message' => 'Error syncing NetSuite Company: ' . $e->getMessage(),
                'status' => 'error',
                'netSuiteCompanyId' => $netSuiteCompanyId,
            ];
        }
    }

    /**
     * Check if the SoapFault is due to NetSuite rate limiting
     */
    protected function isNetSuiteRateLimitError(SoapFault $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'concurrent request limit exceeded') ||
               str_contains($message, 'request blocked') ||
               str_contains($message, 'rate limit') ||
               str_contains($message, 'too many requests') ||
               str_contains($message, 'suitetalk concurrent request limit');
    }

    private function setCompanyDocuments(CompanyInterface $company, Apps $app): void
    {
        $documents = $app->get('NETSUITE_COMPANY_DOCUMENTS');
        if (! $documents) {
            $documents = [
                'Authorized Reseller Program Agreement',
                'Certificate of Insurance (COI)',
                'Credit Application',
                'Customer Agreement Form',
                'NDA',
                'W9',
            ];
        } else {
            $documents = explode(',', $documents);
        }

        foreach ($documents as $document) {
            $company->set($document, ['status' => 'approved', 'comment' => null], 1);
        }
    }
}
