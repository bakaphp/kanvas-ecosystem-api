<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * This action handles the synchronization of the NetSuite Customer Items List,
 * which essentially represents the products that a specific customer or company
 * has access to, along with their specific pricing. The process involves taking
 * this list of products, locating them within the main B2B company database,
 * and creating a dedicated channel for the customer. This enables the promotion
 * of these products to the customer effectively.
 */
class SyncNetSuiteCustomerItemsListAction
{
    protected NetSuiteCustomerService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected CompanyInterface $buyerCompany
    ) {
        $this->service = new NetSuiteCustomerService($app, $mainAppCompany);
    }

    public function execute(): array
    {
        $customerId = 846733; //$this->buyerCompany->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);

        if (! $customerId) {
            throw new Exception('Company not linked to NetSuite');
        }

        $customerInfo = $this->service->getCustomerById($customerId);

        $listOrProductVariantsBarCodeIds = $customerInfo->itemPricingList->itemPricing;

        $createNewChannel = new CreateChannel(
            new Channels(
                app: $this->app,
                company: $this->mainAppCompany,
                user: $this->mainAppCompany->user,
                name: $this->buyerCompany->uuid,
                description: $this->buyerCompany->name . ' channel',
                slug: $this->buyerCompany->uuid
            ),
            $this->mainAppCompany->user
        );
        $channel = $createNewChannel->execute();

        $totalProcessed = 0;
        foreach ($listOrProductVariantsBarCodeIds as $bardCodeId) {
            $variant = Variants::fromApp($this->app)
                    ->fromCompany($this->mainAppCompany)
                    ->where('barcode', $bardCodeId->item->name)
                    ->first();

            if (! $variant) {
                continue;
            }

            $addVariantToChannel = new AddVariantToChannelAction(
                $variant->variantWarehouses()->first(),
                $channel,
                VariantChannel::fromArray([
                    'price' => $bardCodeId->price,
                    'discounted_price' => $bardCodeId->price,
                    'is_published' => true,
                ])
            );
            $addVariantToChannel->execute();
            $totalProcessed++;
        }

        return [
            'channel' => $channel->getId(),
            'company' => $this->buyerCompany->getId(),
            'items' => $listOrProductVariantsBarCodeIds,
            'total_items' => count($listOrProductVariantsBarCodeIds),
            'total_processed' => $totalProcessed,
        ];
    }
}
