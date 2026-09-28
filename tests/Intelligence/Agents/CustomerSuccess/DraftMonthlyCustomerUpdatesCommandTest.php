<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Tests\TestCase;

final class DraftMonthlyCustomerUpdatesCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    /**
     * The action validates the window per account, so an unusable flag has to be caught before the loop
     * or the operator reads the same error once per subscribed organization.
     */
    public function testAnUnusableWindowIsRejectedBeforeAnyAccountIsProcessed(): void
    {
        $this->artisan('kanvas:customer-success:draft-monthly-updates', [
            '--window-days' => DraftCustomerUpdateAction::MAX_WINDOW_DAYS + 1,
        ])
            ->expectsOutputToContain('The release window must be between')
            ->assertFailed();
    }

    public function testAZeroWindowIsRejectedRatherThanDraftingAgainstAnEmptyFeed(): void
    {
        $this->artisan('kanvas:customer-success:draft-monthly-updates', ['--window-days' => 0])
            ->expectsOutputToContain('The release window must be between')
            ->assertFailed();
    }
}
