<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Who the accounting inbox acts as. Without a user the intake can't resolve or create the vendor.
 *
 * The company's AP agent's own user first, so inbox work is attributed to the agent that owns AP; then the
 * company's AI agent user; then whoever owns the receiver.
 */
class InboxActingUserService
{
    public static function resolve(Apps $app, Companies $company, ?Users $receiverOwner = null): ?Users
    {
        return self::apAgentUser($app, $company) ?? self::companyAiUser($company) ?? $receiverOwner;
    }

    private static function apAgentUser(Apps $app, Companies $company): ?Users
    {
        return new AccountingAgentLocatorService()->payables($app, $company)?->user;
    }

    private static function companyAiUser(Companies $company): ?Users
    {
        try {
            return $company->getAiAgentUser();
        } catch (Throwable) {
            // A configured id pointing at a deleted user is no user, not a failed inbound invoice.
            return null;
        }
    }
}
