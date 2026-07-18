<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\DataTransferObject\Concerns;

use Override;

/**
 * Shared by every Salesforce outbound DTO: applies the company's configurable field map
 * (e.g. `CustomFieldEnum::LEAD_FIELDS_MAP`) on top of the fixed standard fields, since each
 * Salesforce org has its own custom picklists/fields that can't be hardcoded.
 */
trait MapsAdditionalFields
{
    protected static function mapAdditionalFields(mixed $fieldsMap, array $entityCustomFields): array
    {
        $additionalFields = [];

        if (! is_array($fieldsMap)) {
            return $additionalFields;
        }

        foreach ($fieldsMap as $localKey => $salesforceKey) {
            if (! is_string($salesforceKey) || $salesforceKey === '' || ! is_string($localKey)) {
                continue;
            }

            $value = $entityCustomFields[$localKey] ?? null;

            if ($value !== null && $value !== '') {
                $additionalFields[$salesforceKey] = $value;
            }
        }

        return $additionalFields;
    }

    #[Override]
    public function toArray(): array
    {
        $data = array_merge(parent::toArray(), $this->additionalFields ?? []);
        unset($data['additionalFields']);

        return array_filter($data, fn ($value) => $value !== null && $value !== '');
    }
}
