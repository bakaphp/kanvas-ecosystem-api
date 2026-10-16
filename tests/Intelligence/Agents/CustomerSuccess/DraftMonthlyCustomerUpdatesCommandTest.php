<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\CustomerSuccess;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CustomerSuccess\DraftCustomerUpdateAction;
use Tests\Intelligence\Agents\CustomerSuccess\Concerns\BuildsNewsletterAudience;
use Tests\TestCase;

final class DraftMonthlyCustomerUpdatesCommandTest extends TestCase
{
    use BuildsNewsletterAudience;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

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

    /**
     * On the cron the console output is discarded, so a failed account that is only printed leaves
     * Sentry with the scheduler's bare "exit code 1" and no way to tell which account or why
     * (KANVAS-ECOSYSTEM-6D7).
     */
    public function testAFailedAccountIsLoggedWithItsContextNotJustPrinted(): void
    {
        // A company id no agent can belong to, so the run takes the no-agent path without drafting.
        $agentlessCompanyId = 2147483000;
        $organization = $this->taggedOrganization(companyId: $agentlessCompanyId);
        $this->linkPerson($organization, 'subscriber@example.test', tagged: true);

        Log::spy();

        $this->artisan('kanvas:customer-success:draft-monthly-updates', [
            '--app_id' => app(Apps::class)->getId(),
        ])
            ->expectsOutputToContain('no Customer Update Agent in company ' . $agentlessCompanyId)
            ->assertFailed();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $context['organization_id'] === $organization->getId()
                && $context['companies_id'] === $agentlessCompanyId)
            ->once();
    }
}
