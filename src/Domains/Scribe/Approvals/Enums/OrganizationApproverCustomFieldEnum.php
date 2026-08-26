<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

/** Custom field on the vendor/customer Organization that names who approves its bills/invoices. */
enum OrganizationApproverCustomFieldEnum: string
{
    case APPROVER_EMAIL = 'ap_approver_email';
}
