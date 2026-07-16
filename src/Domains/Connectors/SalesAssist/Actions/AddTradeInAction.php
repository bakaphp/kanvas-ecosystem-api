<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Store a submitted trade-in form on the lead for the SalesAssist extension
 * to import into the dealer's DMS.
 *
 * CRMs whose API has no trade-in write path (e.g. Reynolds SalesAssist USL)
 * use this instead of pushing the trade upstream. `tradein_data` holds the
 * raw form; `tradein_imported` holds the {active, message, date} banner the
 * extension renders. The banner message is per-CRM, so callers pass it in.
 */
class AddTradeInAction
{
    public function __construct(
        protected Lead $lead,
        protected string $importMessage = 'Trade-In Ready to be imported.',
    ) {
    }

    public function execute(array $message): array
    {
        $formData = $message['data']['form'] ?? [];

        $importer = [
            'active' => 1,
            'message' => $this->importMessage,
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        $this->lead->set(LeadCustomFieldEnum::TRADE_IN_DATA->value, $formData);
        $this->lead->set(LeadCustomFieldEnum::TRADE_IN_IMPORTED->value, $importer);

        return [
            'tradein_data' => $formData,
            'tradein_imported' => $importer,
        ];
    }
}
