<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Salesforce\DataTransferObject\Concerns\MapsAdditionalFields;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Spatie\LaravelData\Data;

class SalesforceOpportunity extends Data
{
    use MapsAdditionalFields;

    public function __construct(
        public string $Name,
        public string $CloseDate,
        public ?string $StageName = null,
        public ?string $AccountId = null,
        public ?array $additionalFields = [],
    ) {
    }

    public static function fromDeal(Deal $deal): self
    {
        $company = $deal->company;
        $fieldsMap = $company?->get(CustomFieldEnum::OPPORTUNITY_FIELDS_MAP->value);

        $organization = $deal->organization;
        $accountId = $organization?->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);

        return new self(
            Name: $deal->title ?: ('Deal ' . $deal->getId()),
            // Opportunity requires CloseDate but Deal has no due-date column — 30 days out is a
            // safe placeholder Salesforce will accept; a company-specific mapping can override
            // it via OPPORTUNITY_FIELDS_MAP.
            CloseDate: Carbon::now()->addDays(30)->format('Y-m-d'),
            StageName: $deal->pipelineStage?->name,
            AccountId: $accountId ? (string) $accountId : null,
            additionalFields: self::mapAdditionalFields($fieldsMap, $deal->getAll()),
        );
    }
}
