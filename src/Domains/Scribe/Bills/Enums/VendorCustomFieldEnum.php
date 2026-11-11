<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Enums;

/** Accounting custom fields read off a vendor Organization. */
enum VendorCustomFieldEnum: string
{
    /** Account number (chart of accounts) a bill from this vendor is coded to when nothing else says. */
    case DEFAULT_EXPENSE_ACCOUNT = 'scribe_default_expense_account';
}
