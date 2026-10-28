<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Baka\Support\Arr;
use Carbon\Carbon;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Store a submitted trade-in form on the lead for the SalesAssist extension
 * to import into the dealer's DMS.
 *
 * CRMs whose API has no trade-in write path (e.g. Reynolds SalesAssist USL)
 * use this instead of pushing the trade upstream. `tradein_data` holds the
 * importer-compatible form; `tradein_imported` holds the {active, message, date} banner the
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
        $data = $message['data'] ?? [];
        $formData = $this->normalizeForm(
            $data['form'] ?? (array_is_list($data) ? Arr::fromLabelValuePairs($data) : $data),
        );

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

    /**
     * Keep the original importer keys and photo arrays for both web and extension forms.
     */
    private function normalizeForm(array $form): array
    {
        $aliases = [
            'vehicle_identification_number_(vin)' => 'vin',
            'transmission' => 'trans',
            'exterior_color' => 'ext_color',
            'interior_color' => 'int_color',
            'odometer' => 'mileage',
            'upload_odometer_photo' => 'odometer_photo',
            'upload_vehicle_photo' => 'vehicle_photos',
        ];
        $normalized = [];

        foreach ($form as $key => $value) {
            $field = $aliases[$key] ?? $key;
            // An explicit importer key wins if both it and a display-label alias are supplied.
            $value = array_key_exists($field, $form) ? $form[$field] : $value;
            $normalized[$field] = in_array($field, ['odometer_photo', 'vehicle_photos'], true)
                ? Arr::wrap($value)
                : $value;
        }

        return $normalized;
    }
}
