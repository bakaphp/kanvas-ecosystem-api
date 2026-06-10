<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Exports;

use Carbon\Carbon;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Exports\OrderExportExcel;
use Kanvas\Users\Models\Users;

/**
 * Mechanics (technician) export. Reuses the generic spreadsheet engine in
 * OrderExportExcel (styling, header rows, logos, chunking) and only overrides
 * the row mapping, since the relevant mechanic columns (availability, location,
 * vehicle) live in user_config custom fields and are not plain attributes.
 */
class MechanicsExport extends OrderExportExcel
{
    private string $exportTimezone;
    private bool $useFieldPaths;

    public function __construct($data, $query = null, $timezone = null)
    {
        parent::__construct($data, $query, $timezone);
        $this->exportTimezone = $timezone ?? config('app.timezone', 'UTC');
        $this->useFieldPaths = ! empty($data['field_paths']);
    }

    public function map($mechanic): array
    {
        // When the client supplies a field_mapper the action fills field_paths and
        // we defer to the generic dot-notation mapping; otherwise use the curated
        // mechanic columns below, which read user_config custom fields directly.
        if ($this->useFieldPaths) {
            return parent::map($mechanic);
        }

        /** @var Users $mechanic */
        $vehicleInfo = $mechanic->get(CustomFieldEnum::MECHANIC_VEHICLE_INFO->value);

        return [
            $mechanic->getId(),
            trim($mechanic->firstname . ' ' . $mechanic->lastname),
            $mechanic->email,
            $mechanic->phone_number ?? $mechanic->cell_phone_number ?? null,
            $mechanic->get(CustomFieldEnum::MECHANIC_AVAILABILITY->value),
            $mechanic->get(CustomFieldEnum::MECHANIC_LAT->value),
            $mechanic->get(CustomFieldEnum::MECHANIC_LNG->value),
            is_array($vehicleInfo) ? json_encode($vehicleInfo) : $vehicleInfo,
            rescue(
                fn () => $mechanic->getCurrentCompany()?->name,
                $mechanic->companies->first()?->name,
                false
            ),
            $mechanic->roles->pluck('name')->implode(', '),
            $mechanic->created_at instanceof Carbon
                ? $mechanic->created_at->setTimezone($this->exportTimezone)->format('Y-m-d H:i:s')
                : $mechanic->created_at,
        ];
    }
}
