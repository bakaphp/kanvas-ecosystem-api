<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Guild\Deals\Models\Deal;
use Override;
use Spatie\LaravelData\Data;

class ZohoDeal extends Data
{
    /**
     * Zoho requires Stage on create; this is the first stage of its stock deal pipeline, the
     * counterpart of the 'New Lead' a lead falls back to.
     */
    public const string DEFAULT_STAGE = 'Qualification';

    public function __construct(
        public string $Deal_Name,
        public string $Stage,
        public ?string $Description = null,
        public ?string $Owner = null,
        public ?string $ZCRM_Lead_ID = null,
        public array $additionalFields = [],
    ) {
    }

    /**
     * @todo Read the deal person's default address first and emit the address fields from it,
     *       falling back to the mapped custom fields only when the person has none (docs/TODO.md).
     */
    public static function fromDeal(Deal $deal): self
    {
        $company = $deal->company;
        $stage = $deal->pipelineStage;
        $owner = $deal->owner?->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value)
            ?? $company->get(CustomFieldEnum::DEFAULT_OWNER->value);

        return new self(
            Deal_Name: (string) $deal->title,
            Stage: (string) ($stage?->get(CustomFieldEnum::ZOHO_STATUS_NAME->value) ?? $stage?->name ?? self::DEFAULT_STAGE),
            Description: $deal->description,
            Owner: Str::trimToNull((string) ($owner ?? '')),
            ZCRM_Lead_ID: Str::trimToNull((string) ($deal->lead?->get(CustomFieldEnum::ZOHO_LEAD_ID->value) ?? '')),
            additionalFields: ZohoFieldMapService::map(ZohoFieldMapService::dealMapFor($company), $deal->getAll()),
        );
    }

    #[Override]
    public function toArray(): array
    {
        $data = array_merge(parent::toArray(), $this->additionalFields);
        unset($data['additionalFields']);

        return array_filter($data, fn ($value) => ! empty($value));
    }
}
