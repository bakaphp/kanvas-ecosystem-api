<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Fixtures;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\EngageableInterface;
use Kanvas\Guild\Customers\Models\People;
use Override;

/**
 * A non-lead engageable backed by a real table, so the reference path can be exercised before any
 * production model (ScribeInvoice, ScribeQuote) implements the contract.
 */
class EngageablePeople extends People implements EngageableInterface
{
    public const string ALIAS = 'test_engageable';

    #[Override]
    public function getEngagementApp(): AppInterface
    {
        return $this->app;
    }

    #[Override]
    public function getEngagementCompany(): CompanyInterface
    {
        return $this->company;
    }

    #[Override]
    public function getEngagementChannelSlug(): string
    {
        return (string) $this->uuid;
    }
}
