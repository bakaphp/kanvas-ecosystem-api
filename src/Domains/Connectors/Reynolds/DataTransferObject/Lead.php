<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject;

use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Spatie\LaravelData\Data;

/**
 * Outbound (Kanvas → Reynolds) Lead projection.
 *
 * Vehicle of interest and trade-in are intentionally NOT projected here even
 * though we receive them on the Pull side. Reynolds is not the authoritative
 * source for those — other Kanvas processes (inventory sync, lead-form
 * submission, etc.) own that data — so we never round-trip them back to R&R.
 */
class Lead extends Data
{
    public function __construct(
        public readonly ?string $prospectId,
        public readonly string $prospectCategory,
        public readonly string $prospectType,
        public readonly ?string $prospectStatus,
        public readonly ?string $providerName,
        public readonly ?string $prospectNote,
        public readonly ?string $isAiGenerated,
        public readonly ?string $primarySalesPerson,
        public readonly Customer $customer,
    ) {
    }

    public static function fromLead(LeadModel $lead): self
    {
        $prospectId = $lead->get(CustomFieldEnum::PROSPECT_ID->value);
        $prospectType = $lead->get(CustomFieldEnum::PROSPECT_TYPE->value)
            ?? ($lead->type()->first()?->name ?? 'Internet');

        return new self(
            prospectId: $prospectId !== null ? (string) $prospectId : null,
            prospectCategory: 'Sales',
            prospectType: (string) $prospectType,
            prospectStatus: $lead->status()->first()?->name,
            providerName: $lead->source()->first()?->name ?? 'Kanvas',
            prospectNote: $lead->description,
            isAiGenerated: null,
            primarySalesPerson: self::ownerName($lead),
            customer: Customer::fromPeople($lead->people),
        );
    }

    /**
     * Build the Prospect XML section.
     *
     * ProspectId is intentionally NOT included here — USL puts it in a
     * separate `<Identifier>` sibling block inside Record (mirrors the
     * shape LDU inbound envelopes use and what the USL Note sub-flow
     * already does). ISL leaves it blank so R&R generates the id.
     */
    public function toProspect(): array
    {
        return array_filter([
            'ProspectCategory' => $this->prospectCategory,
            'ProviderName' => $this->providerName,
            'IsAiGenerated' => $this->isAiGenerated,
            'ProspectType' => $this->prospectType,
            'ProspectStatus' => $this->prospectStatus,
            'ProspectNote' => $this->prospectNote,
            'PrimarySalesPerson' => $this->primarySalesPerson,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function ownerName(LeadModel $lead): ?string
    {
        $owner = $lead->owner()->first();
        if ($owner === null) {
            return null;
        }

        $name = trim(($owner->firstname ?? '') . ' ' . ($owner->lastname ?? ''));

        return $name !== '' ? $name : null;
    }
}
