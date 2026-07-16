<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Reynolds counterpart of Elead's AddTradeInAction.
 *
 * Reynolds SalesAssist USL has no trade-in sub-flow, so instead of pushing
 * the trade to R&R we store the submitted form on the lead as custom fields.
 * The SalesAssist extension picks up `tradein_data` + `tradein_imported` and
 * imports the trade into the dealer's DMS from the browser.
 */
class AddTradeInAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message): array
    {
        $formData = $message['data']['form'] ?? [];

        $importer = [
            'active' => 1,
            'message' => 'Trade-In Ready to be imported into Reynolds.',
            'date' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        $this->lead->set(CustomFieldEnum::TRADE_IN_DATA->value, $formData);
        $this->lead->set(CustomFieldEnum::TRADE_IN_IMPORTED->value, $importer);

        return [
            'tradein_data' => $formData,
            'tradein_imported' => $importer,
        ];
    }
}
