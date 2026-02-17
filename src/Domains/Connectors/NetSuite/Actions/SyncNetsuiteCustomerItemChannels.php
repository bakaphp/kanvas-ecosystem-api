<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Channels\Models\Channels;

class SyncNetsuiteCustomerItemChannels
{
    public function __construct(
        protected CompanyInterface $company,
        protected CompanyInterface $buyerCompany,
    ) {
    }

    /**
     * Execute the channel synchronization.
     *
     * @return array Summary of the operation
     */
    public function execute(): array
    {
        $channel = Channels::where('companies_id', $this->company->getId())
            ->where('slug', (string) $this->buyerCompany->uuid)
            ->first();

        if (! $channel) {
            return [
                'company_id' => $this->buyerCompany->getId(),
                'company_name' => $this->buyerCompany->name,
                'action' => 'no_channel_found',
                'channel' => null,
            ];
        }

        if ($this->buyerCompany->is_active) {
            $channel->update(['is_published' => 1]);
            $action = 'published';
        } else {
            $channel->update(['is_published' => 0]);
            $action = 'unpublished';
        }

        return [
            'company_id' => $this->buyerCompany->getId(),
            'company_name' => $this->buyerCompany->name,
            'action' => $action,
            'channel' => $channel,
        ];
    }
}
