<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Resolves the approver email for a pending item from its vendor/customer Organization —
 * the same organization-level custom field for every approval type, so a new type only needs
 * a new match arm here, mirroring ResolveApprovalAction's own action_type dispatch.
 */
class ResolveApproverEmailAction
{
    public function __construct(
        protected readonly string $targetType,
        protected readonly int $targetId,
    ) {
    }

    public function execute(): ?string
    {
        $organization = match ($this->targetType) {
            'bill' => Bill::query()->where('id', $this->targetId)->first()?->vendor,
            'invoice' => Invoice::query()->where('id', $this->targetId)->first()?->customer,
            default => null,
        };

        if ($organization === null) {
            return null;
        }

        $email = trim((string) $organization->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, ''));

        return $email !== '' ? $email : null;
    }
}
