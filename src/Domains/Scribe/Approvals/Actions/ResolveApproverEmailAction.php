<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Resolves the approver email(s) for a pending item from its vendor/customer Organization —
 * the same organization-level lookup for every approval type, so a new type only needs a new
 * match arm here, mirroring ResolveApprovalAction's own action_type dispatch.
 */
class ResolveApproverEmailAction
{
    public function __construct(
        protected readonly string $targetType,
        protected readonly int $targetId,
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        $organization = match ($this->targetType) {
            'bill' => Bill::query()->where('id', $this->targetId)->first()?->vendor,
            'invoice' => Invoice::query()->where('id', $this->targetId)->first()?->customer,
            default => null,
        };

        return $organization !== null ? self::resolveForOrganization($organization) : [];
    }

    /**
     * The Organization's own OrganizationApprover Users take priority; falls back to the
     * legacy ap_approver_email custom field for organizations not yet migrated to the table.
     *
     * @return list<string>
     */
    public static function resolveForOrganization(Organization $organization): array
    {
        $emails = OrganizationApprover::emailsFor($organization);

        if ($emails !== []) {
            return $emails;
        }

        $fallback = trim((string) $organization->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, ''));

        return $fallback !== '' ? [$fallback] : [];
    }
}
