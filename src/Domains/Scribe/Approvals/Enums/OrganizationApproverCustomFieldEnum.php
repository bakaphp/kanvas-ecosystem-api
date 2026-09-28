<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

/** Custom fields on the vendor/customer Organization set by the approver spreadsheet import. */
enum OrganizationApproverCustomFieldEnum: string
{
    case APPROVER_EMAIL = 'ap_approver_email';

    // Vendor name exactly as spelled in the approver spreadsheet, kept separate from the Organization's own (often differently-formatted) name so the tracking sheet shows the human-recognizable spelling.
    case VENDOR_NAME = 'ap_approver_vendor_name';
}
