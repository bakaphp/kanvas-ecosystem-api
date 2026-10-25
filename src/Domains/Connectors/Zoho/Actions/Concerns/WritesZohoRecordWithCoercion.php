<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Kanvas\Connectors\Zoho\Services\ZohoFieldTypeService;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Zoho rejects the whole record when a single value doesn't match its field type — a form hands us
 * "$50,000" for a currency field, and 24 leads never reached the CRM because of it. We can't know
 * each tenant's field types up front (the map is per-company config), so we let Zoho be the judge:
 * on INVALID_DATA, coerce exactly the fields it named into the types it asked for and retry once,
 * dropping any value we can't make fit rather than losing the record over it.
 */
trait WritesZohoRecordWithCoercion
{
    protected function createRecord(ZohoRecordsModule $module, array &$data): ?object
    {
        try {
            return $module->create($data);
        } catch (ApiError $e) {
            $data = $this->coerceRejectedPayload($e, $data) ?? throw $e;

            return $module->create($data);
        }
    }

    protected function updateRecord(ZohoRecordsModule $module, string $recordId, array &$data): ?object
    {
        try {
            return $module->update($recordId, $data);
        } catch (ApiError $e) {
            $data = $this->coerceRejectedPayload($e, $data) ?? throw $e;

            return $module->update($recordId, $data);
        }
    }

    protected function coerceRejectedPayload(ApiError $e, array $data): ?array
    {
        $body = json_decode((string) $e->response()->getBody(), true);

        return ZohoFieldTypeService::coercePayload($data, is_array($body) ? $body : null);
    }
}
