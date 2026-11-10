<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * A lead converted in Zoho has a deal Kanvas may never have heard of: the deal webhook is optional
 * per tenant, and the converted lead itself is read-only. This pulls that deal in, linked to the
 * lead, so everything that fans out over the lead's deals reaches it.
 */
class PullConvertedLeadDealAction
{
    public function __construct(
        protected Lead $lead,
        protected ZohoService $zohoService,
    ) {
    }

    /**
     * Null when the lead is not in Zoho, is not converted, or Zoho has no deal for it.
     */
    public function execute(): ?Deal
    {
        $zohoLeadId = Str::trimToNull((string) $this->lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));

        if ($zohoLeadId === null) {
            return null;
        }

        $detail = $this->lead->get(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value);

        if (! is_array($detail)) {
            $detail = $this->zohoService->getConvertedLeadDetail($zohoLeadId);

            if ($detail === null) {
                return null;
            }

            $this->lead->set(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value, $detail);
        }

        $zohoDealId = Str::trimToNull((string) ($detail['deal'] ?? ''));

        if ($zohoDealId === null) {
            return null;
        }

        return $this->pullDeal($zohoDealId);
    }

    protected function pullDeal(string $zohoDealId): ?Deal
    {
        return new PullZohoDealAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user,
            $zohoDealId,
            lead: $this->lead,
        )->execute();
    }
}
